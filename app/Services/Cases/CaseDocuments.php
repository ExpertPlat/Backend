<?php

namespace App\Services\Cases;

use App\Enums\CaseDocumentScanStatus;
use App\Exceptions\PrivacyException;
use App\Jobs\Cases\ScanCaseDocument;
use App\Models\CaseDocument;
use App\Models\CaseDocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CaseDocuments
{
    public function __construct(private readonly CaseWorkflow $workflow, private readonly CaseDocumentStorage $storage) {}

    public function cleanupUncommitted(): void
    {
        $this->storage->cleanupUncommitted();
    }

    public function upload(User $user, int $id, ?int $document, array $data, UploadedFile $file): CaseDocument
    {
        return DB::transaction(function () use ($user, $id, $document, $data, $file) {
            $case = $this->workflow->lock($user, $id, (int) $data['expectedVersion']);
            if ($document === null) {
                if ($case->documents()->whereNull('deleted_at')->count() >= config('case_intake.max_documents')) {
                    throw ValidationException::withMessages(['file' => ['The active document limit has been reached.']]);
                }
                $record = new CaseDocument;
                $record->forceFill(['case_id' => $case->id])->save();
            } else {
                $record = $case->documents()->whereNull('deleted_at')->findOrFail($document);
                foreach ($case->serviceScopes()->whereNotNull('submitted_at')->get() as $scope) {
                    if (collect($scope->policy_snapshot['documentVersions'] ?? [])->contains('documentId', $record->id)) {
                        throw new PrivacyException('SUBMITTED_DOCUMENT_IMMUTABLE', 'A submitted scope references this document revision.');
                    }
                }
            }
            $sequence = ($record->versions()->max('version') ?? 0) + 1;
            if ($sequence > config('case_intake.max_revisions')) {
                throw ValidationException::withMessages(['file' => ['The document revision limit has been reached.']]);
            }
            $stored = $this->storage->store($file);
            $version = new CaseDocumentVersion;
            $version->forceFill($stored + ['document_id' => $record->id, 'version' => $sequence, 'title' => $data['title'], 'category' => $data['category'], 'scan_status' => CaseDocumentScanStatus::Pending])->save();
            $record->current_version_id = $version->id;
            $record->save();
            $this->workflow->touch($case, $user, $document ? 'case.document_replaced' : 'case.document_uploaded');
            ScanCaseDocument::dispatch($version->id)->afterCommit();
            $record->setAttribute('case_version', $case->version);

            return $record->load('currentVersion');
        });
    }

    public function delete(User $user, int $id, int $document, array $data): CaseDocument
    {
        return DB::transaction(function () use ($user, $id, $document, $data) {
            $case = $this->workflow->lock($user, $id, (int) $data['expectedVersion']);
            $record = $case->documents()->whereNull('deleted_at')->findOrFail($document);
            foreach ($case->serviceScopes()->whereNotNull('submitted_at')->get() as $scope) {
                if (collect($scope->policy_snapshot['documentVersions'] ?? [])->contains('documentId', $record->id)) {
                    throw new PrivacyException('SUBMITTED_DOCUMENT_IMMUTABLE', 'A submitted scope references this document revision.');
                }
            }
            $record->deleted_at = now();
            $record->save();
            $this->workflow->touch($case, $user, 'case.document_removed');
            $record->setAttribute('case_version', $case->version);

            return $record->load('currentVersion');
        });
    }
}
