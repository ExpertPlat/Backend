<?php

namespace App\Console\Commands;

use App\Enums\DataRequestStatus;
use App\Models\DataRightsRequest;
use App\Models\IdempotencyRecord;
use App\Services\Privacy\AuditWriter;
use App\Services\Privacy\DataRightsManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CleanupPrivacyArtifacts extends Command
{
    protected $signature = 'privacy:cleanup';

    protected $description = 'Remove expired export artifacts and idempotency responses, without deleting audit or user records';

    public function handle(AuditWriter $audit, DataRightsManager $manager): int
    {
        $failures = 0;
        DataRightsRequest::query()->where('status', 'processing')->where('processing_lease_until', '<=', now())
            ->chunkById(100, function ($rows) use ($manager): void {
                foreach ($rows as $record) {
                    DB::transaction(function () use ($record, $manager): void {
                        $row = DataRightsRequest::query()->lockForUpdate()->findOrFail($record->id);
                        if ($row->status !== DataRequestStatus::Processing || $row->processing_lease_until?->isFuture()) {
                            return;
                        }
                        $row->forceFill(['processing_token' => null, 'processing_lease_until' => null]);
                        $row->items()->update(['status' => 'failed', 'reason_code' => 'PROCESSING_LEASE_EXPIRED']);
                        $manager->transition($row, DataRequestStatus::Failed, reason: 'PROCESSING_LEASE_EXPIRED');
                    });
                }
            });
        DataRightsRequest::query()->whereNotNull('artifact_path')->where('artifact_expires_at', '<=', now())
            ->chunkById(100, function ($rows) use ($audit, &$failures): void {
                foreach ($rows as $record) {
                    try {
                        $disk = Storage::disk($record->artifact_disk);
                        if ($disk->exists($record->artifact_path) && ! $disk->delete($record->artifact_path)) {
                            $failures++;

                            continue;
                        }
                        DB::transaction(function () use ($record, $audit): void {
                            $locked = DataRightsRequest::query()->lockForUpdate()->findOrFail($record->id);
                            if ($locked->artifact_path !== $record->artifact_path) {
                                return;
                            }
                            $locked->forceFill(['artifact_path' => null, 'artifact_disk' => null])->save();
                            $audit->write(null, 'export.artifact_expired', 'data_request', $locked->id);
                        });
                    } catch (Throwable) {
                        $failures++;
                    }
                }
            });
        // Only delete old orphan artifacts in this dedicated export disk.
        // A grace interval longer than the worker lease avoids racing normal writes.
        try {
            $disk = Storage::disk(config('data_rights.disk'));
            foreach ($disk->allFiles('requests') as $path) {
                if ($disk->lastModified($path) > now()->subHours(config('data_rights.artifact_hours') + 1)->timestamp) {
                    continue;
                }
                if (! DataRightsRequest::query()->where('artifact_disk', config('data_rights.disk'))->where('artifact_path', $path)->exists()
                    && ! $disk->delete($path)) {
                    $failures++;
                }
            }
        } catch (Throwable) {
            $failures++;
        }
        IdempotencyRecord::query()->where('expires_at', '<=', now())->delete();
        $this->info($failures === 0 ? 'Privacy cleanup completed.' : 'Privacy cleanup needs retry; no private details were logged.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
