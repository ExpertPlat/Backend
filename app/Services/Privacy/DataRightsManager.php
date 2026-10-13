<?php

namespace App\Services\Privacy;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Exceptions\PrivacyException;
use App\Jobs\Privacy\ProcessDataRightsRequest;
use App\Models\DataRightsRequest;
use App\Models\DataRightsRequestItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DataRightsManager
{
    public function __construct(private readonly AuditWriter $audit, private readonly ProfileManager $profiles) {}

    public function create(User $user, array $data, Carbon $confirmedAt): DataRightsRequest
    {
        $days = config('data_rights.due_days');
        if (! is_int($days) || $days < 1 || $days > 365) {
            throw new PrivacyException('POLICY_CONFIGURATION_REQUIRED', 'An approved data-request response period must be configured.');
        }

        return DB::transaction(function () use ($user, $data, $confirmedAt, $days): DataRightsRequest {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->dataRequests()->where('type', $data['type'])->whereIn('status', DataRequestStatus::openValues())->exists()) {
                throw new PrivacyException('DUPLICATE_OPEN_REQUEST', 'An open request of this type already exists.');
            }
            $record = new DataRightsRequest;
            $record->forceFill(['reference' => (string) Str::uuid(), 'user_id' => $user->id, 'type' => $data['type'],
                'scope' => 'account', 'status' => DataRequestStatus::Requested, 'version' => 1,
                'identity_confirmed_at' => $confirmedAt, 'requested_at' => now(), 'due_at' => now()->addDays($days)])->save();
            foreach (config('data_rights.sections') as $section) {
                (new DataRightsRequestItem)->forceFill(['request_id' => $record->id, 'record_class' => $section,
                    'scope_reference' => 'account', 'status' => 'pending'])->save();
            }
            $this->audit->write($user, 'user.data_request_created', 'data_request', $record->id, null, 'requested');
            ProcessDataRightsRequest::dispatch($record->id)->afterCommit();

            return $record->load('items');
        });
    }

    public function cancel(User $user, int $id, int $version): DataRightsRequest
    {
        return DB::transaction(function () use ($user, $id, $version): DataRightsRequest {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $record = $user->dataRequests()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->profiles->version($record->version, $version);
            $this->transition($record, DataRequestStatus::Cancelled, $user);
            $record->items()->whereIn('status', ['pending', 'deferred', 'failed'])->update(['status' => 'cancelled']);

            return $record->load('items');
        });
    }

    // Only call on a locked row inside the domain transaction. No HTTP action accepts a state.
    public function transition(DataRightsRequest $record, DataRequestStatus $target, ?User $actor = null, ?string $reason = null): void
    {
        if (! $record->status->canTransitionTo($target)) {
            throw new PrivacyException('INVALID_STATE_TRANSITION', 'This request transition is not allowed.');
        }
        if ($target === DataRequestStatus::Completed && ($record->type !== DataRequestType::Export
            || ! $record->artifact_path || ! $record->artifact_checksum || ! $record->artifact_expires_at)) {
            throw new PrivacyException('INVALID_STATE_TRANSITION', 'Completion requires a prepared export artifact.');
        }
        $from = $record->status;
        $record->status = $target;
        $record->version++;
        $record->reason_code = $reason;
        if ($target === DataRequestStatus::Processing) {
            $record->started_at ??= now();
        }
        if (in_array($target, [DataRequestStatus::Completed, DataRequestStatus::Rejected, DataRequestStatus::Cancelled], true)) {
            $record->completed_at = now();
        }
        $record->save();
        $this->audit->write($actor, 'data_request.transitioned', 'data_request', $record->id, $from->value, $target->value, $reason);
    }
}
