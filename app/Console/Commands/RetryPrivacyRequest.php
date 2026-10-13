<?php

namespace App\Console\Commands;

use App\Enums\DataRequestStatus;
use App\Jobs\Privacy\ProcessDataRightsRequest;
use App\Models\DataRightsRequest;
use App\Services\Privacy\AuditWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryPrivacyRequest extends Command
{
    protected $signature = 'privacy:retry {request : Numeric request ID}';

    protected $description = 'Requeue a requested or failed data-rights request; never execute a deferred deletion';

    public function handle(AuditWriter $audit): int
    {
        if (! ctype_digit((string) $this->argument('request'))) {
            $this->error('A numeric request ID is required.');

            return self::FAILURE;
        }
        $queued = DB::transaction(function () use ($audit): bool {
            $row = DataRightsRequest::query()->lockForUpdate()->find($this->argument('request'));
            if (! $row || ! in_array($row->status, [DataRequestStatus::Requested, DataRequestStatus::Failed], true)) {
                return false;
            }
            $audit->write(null, 'data_request.requeued', 'data_request', $row->id, reason: 'OPERATOR_RETRY');
            ProcessDataRightsRequest::dispatch($row->id)->afterCommit();

            return true;
        });
        $queued ? $this->info('Request queued.') : $this->error('Request is absent or not eligible for retry.');

        return $queued ? self::SUCCESS : self::FAILURE;
    }
}
