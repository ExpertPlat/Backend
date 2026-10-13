<?php

namespace App\Console\Commands;

use App\Enums\CaseDocumentScanStatus;
use App\Jobs\Cases\ScanCaseDocument;
use App\Models\CaseDocument;
use App\Models\CaseDocumentVersion;
use App\Models\CaseRecord;
use App\Models\User;
use App\Services\Cases\CaseWorkflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryCaseDocumentScans extends Command
{
    protected $signature = 'case-documents:retry {version : Failed or stale pending revision ID}';

    protected $description = 'Redispatch a failed or stale pending case scan after fixing the scanner/worker';

    public function handle(CaseWorkflow $workflow): int
    {
        $version = CaseDocumentVersion::query()->findOrFail($this->argument('version'));
        $doc = CaseDocument::query()->findOrFail($version->document_id);
        $case = CaseRecord::query()->findOrFail($doc->case_id);
        DB::transaction(function () use ($case, $doc, $version, $workflow) {
            User::query()->whereKey($case->user_id)->lockForUpdate()->firstOrFail();
            $case = CaseRecord::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $row = CaseDocumentVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            abort_if($doc->deleted_at || ! $case->status->editable() || ! in_array($row->scan_status, [CaseDocumentScanStatus::Failed, CaseDocumentScanStatus::Pending], true), 409);
            abort_if($row->scan_status === CaseDocumentScanStatus::Pending && $row->updated_at->gt(now()->subMinutes(10)), 409);
            $row->forceFill(['scan_status' => CaseDocumentScanStatus::Pending, 'scan_reason' => null, 'scanned_at' => null])->save();
            $workflow->touch($case, null, 'case.document_scan_retried');
            ScanCaseDocument::dispatch($row->id)->afterCommit();
        });
        $this->info('Scan queued.');

        return self::SUCCESS;
    }
}
