<?php

namespace App\Jobs\Cases;

use App\Enums\CaseDocumentScanStatus;
use App\Models\CaseDocument;
use App\Models\CaseDocumentVersion;
use App\Models\CaseRecord;
use App\Models\User;
use App\Services\Cases\CaseDocumentStorage;
use App\Services\Cases\CaseWorkflow;
use App\Services\Cases\DocumentScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScanCaseDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $versionId) {}

    public function handle(DocumentScanner $scanner, CaseDocumentStorage $storage, CaseWorkflow $workflow): void
    {
        $version = CaseDocumentVersion::query()->find($this->versionId);
        if (! $version || $version->scan_status !== CaseDocumentScanStatus::Pending) {
            return;
        }
        $document = CaseDocument::query()->findOrFail($version->document_id);
        if ($document->deleted_at !== null) {
            return;
        }
        $case = CaseRecord::query()->findOrFail($document->case_id);
        $directory = storage_path('app/private/case-scan');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $temporary = $directory.'/'.Str::uuid().'.tmp';
        try {
            $bytes = $storage->bytes($version);
            $handle = fopen($temporary, 'x');
            if ($handle === false) {
                throw new \RuntimeException('Cannot stage document scan.');
            }
            try {
                chmod($temporary, 0600);
                fwrite($handle, $bytes);
            } finally {
                fclose($handle);
            }
            $result = $scanner->scan($temporary);
        } catch (\Throwable) {
            $result = CaseDocumentScanStatus::Failed;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        DB::transaction(function () use ($case, $version, $result, $workflow) {
            User::query()->whereKey($case->user_id)->lockForUpdate()->firstOrFail();
            $locked = CaseRecord::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $row = CaseDocumentVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($row->scan_status !== CaseDocumentScanStatus::Pending) {
                return;
            }
            $row->forceFill(['scan_status' => $result, 'scan_reason' => match ($result) {
                CaseDocumentScanStatus::Clean => null,CaseDocumentScanStatus::Rejected => 'CONTENT_REJECTED',default => 'SCAN_UNAVAILABLE'
            }, 'scanned_at' => now()])->save();
            // v7: a scan never bumps the Case version (open runs, briefs and the page's expectedVersion stay valid);
            // before submission it only invalidates the readiness confirmation, so submit re-checks the documents.
            if ($locked->submitted_at === null && $locked->readiness_confirmation !== null) {
                $locked->forceFill(['readiness_confirmation' => null, 'confirmed_at' => null])->save();
            }
            app(\App\Services\Privacy\AuditWriter::class)->write(null, 'case.document_scanned', 'case', $locked->id, reason: $row->scan_reason);
        });
    }
}
