<?php

namespace App\Jobs\Privacy;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Models\DataRightsRequest;
use App\Models\User;
use App\Services\Privacy\AccountExport;
use App\Services\Privacy\DataRightsManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessDataRightsRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $requestId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(DataRightsManager $manager, AccountExport $export): void
    {
        $record = DB::transaction(function () use ($manager): ?DataRightsRequest {
            $row = DataRightsRequest::query()->lockForUpdate()->find($this->requestId);
            if (! $row) {
                return null;
            }
            if ($row->status === DataRequestStatus::Processing) {
                if ($row->processing_lease_until?->isFuture()) {
                    // A duplicate worker must not race the owner. A queued delivery returns later.
                    if ($this->job) {
                        $this->release(60);
                    }

                    return null;
                }
                $manager->transition($row, DataRequestStatus::Failed, reason: 'PROCESSING_LEASE_EXPIRED');
            }
            if (! in_array($row->status, [DataRequestStatus::Requested, DataRequestStatus::Failed], true)) {
                return null;
            }
            $row->processing_token = (string) Str::uuid();
            $row->processing_lease_until = now()->addMinutes(config('data_rights.lease_minutes'));
            $manager->transition($row, DataRequestStatus::Processing);

            return $row;
        });
        if (! $record) {
            return;
        }
        $path = null;
        $disk = config('data_rights.disk');
        try {
            if ($record->type === DataRequestType::Deletion) {
                DB::transaction(function () use ($record, $manager): void {
                    $row = $this->ownedLease($record);
                    if (! $row) {
                        return;
                    }
                    $row->outcome = ['execution' => 'deferred', 'deleted' => false, 'anonymized' => false];
                    $row->items()->update(['status' => 'deferred', 'reason_code' => 'RETENTION_POLICY_PENDING', 'review_at' => $row->due_at]);
                    $row->processing_token = null;
                    $row->processing_lease_until = null;
                    $manager->transition($row, DataRequestStatus::Deferred, reason: 'RETENTION_POLICY_PENDING');
                });

                return;
            }
            $user = User::query()->findOrFail($record->user_id);
            $json = json_encode($export->build($user), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $path = 'requests/'.$record->reference.'/'.$record->processing_token.'.enc';
            if (! Storage::disk($disk)->put($path, Crypt::encryptString($json), ['visibility' => 'private'])) {
                throw new RuntimeException('Export storage operation failed.');
            }
            // Verify actual stored bytes before recording completed.
            $stored = Crypt::decryptString(Storage::disk($disk)->get($path));
            if (! hash_equals(hash('sha256', $json), hash('sha256', $stored))) {
                throw new RuntimeException('Export integrity verification failed.');
            }
            $committed = DB::transaction(function () use ($record, $manager, $path, $disk, $json): bool {
                $row = $this->ownedLease($record);
                if (! $row) {
                    return false;
                }
                $row->forceFill(['artifact_disk' => $disk, 'artifact_path' => $path,
                    'artifact_checksum' => hash('sha256', $json), 'artifact_size' => strlen($json),
                    'artifact_expires_at' => now()->addHours(config('data_rights.artifact_hours')),
                    'outcome' => ['format' => 'solveit.account-export.v1', 'sections' => config('data_rights.sections')],
                    'processing_token' => null, 'processing_lease_until' => null]);
                $row->items()->update(['status' => 'exported', 'reason_code' => null]);
                $manager->transition($row, DataRequestStatus::Completed);

                return true;
            });
            if (! $committed) {
                Storage::disk($disk)->delete($path);
            }
        } catch (Throwable) {
            if ($path) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable) {
                    // Orphan cleanup handles failed deletes. Never log the internal path or plaintext.
                }
            }
            DB::transaction(function () use ($record, $manager): void {
                $row = $this->ownedLease($record);
                if ($row) {
                    $row->processing_token = null;
                    $row->processing_lease_until = null;
                    $row->items()->update(['status' => 'failed', 'reason_code' => 'PROCESSING_FAILED']);
                    $manager->transition($row, DataRequestStatus::Failed, reason: 'PROCESSING_FAILED');
                }
            });
            // Queue exception storage receives a safe generic exception, not a provider trace containing PII.
            throw new RuntimeException('Data-rights processing failed.');
        }
    }

    private function ownedLease(DataRightsRequest $expected): ?DataRightsRequest
    {
        $row = DataRightsRequest::query()->lockForUpdate()->find($expected->id);

        return $row && $row->status === DataRequestStatus::Processing && $row->processing_token === $expected->processing_token ? $row : null;
    }
}
