<?php

namespace App\Http\Controllers\Api\User\Cases;

use App\Enums\CaseDocumentScanStatus;
use App\Exceptions\PrivacyException;
use App\Http\Controllers\Api\User\Privacy\PrivacyResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cases\CaseListRequest;
use App\Http\Requests\Cases\CaseMutationRequest;
use App\Http\Requests\Cases\CaseUploadRequest;
use App\Http\Resources\Cases\CaseDocumentResource;
use App\Http\Resources\Cases\CaseDocumentVersionResource;
use App\Models\CaseRecord;
use App\Services\Cases\CaseDocuments;
use App\Services\Cases\CaseDocumentStorage;
use App\Services\Privacy\AuditWriter;
use App\Services\Privacy\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CaseDocumentController extends Controller
{
    use PrivacyResponses;

    public function index(CaseListRequest $request, int $case): JsonResponse
    {
        $row = $this->owned($request, $case);

        return $this->listing($row->documents()->getQuery()->whereNull('deleted_at')->with('currentVersion'), $request->validated(), CaseDocumentResource::class, ['id' => 'id']);
    }

    public function versions(CaseListRequest $request, int $case, int $document): JsonResponse
    {
        $doc = $this->owned($request, $case)->documents()->whereNull('deleted_at')->findOrFail($document);

        return $this->listing($doc->versions()->getQuery(), $request->validated(), CaseDocumentVersionResource::class, ['version' => 'version']);
    }

    public function store(CaseUploadRequest $request, int $case, CaseDocuments $documents, Idempotency $idempotency): JsonResponse
    {
        return $this->upload($request, $case, null, $documents, $idempotency);
    }

    public function replace(CaseUploadRequest $request, int $case, int $document, CaseDocuments $documents, Idempotency $idempotency): JsonResponse
    {
        return $this->upload($request, $case, $document, $documents, $idempotency);
    }

    private function upload(CaseUploadRequest $request, int $case, ?int $document, CaseDocuments $documents, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('update', $this->owned($request, $case));
        $data = $request->validated();
        unset($data['file']);
        $file = $request->file('file');
        $fingerprint = $data + ['file' => ['sha256' => hash_file('sha256', $file->getRealPath()), 'extension' => strtolower($file->getClientOriginalExtension()), 'mimeType' => $file->getMimeType()]];
        try {
            return $idempotency->run($request, $fingerprint, fn () => $this->item(new CaseDocumentResource($documents->upload($request->user(), $case, $document, $data, $file)), 'Document revision stored privately.', 201));
        } finally {
            $documents->cleanupUncommitted();
        }
    }

    public function destroy(CaseMutationRequest $request, int $case, int $document, CaseDocuments $documents, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('update', $this->owned($request, $case));

        return $idempotency->run($request, $request->validated(), fn () => $this->item(new CaseDocumentResource($documents->delete($request->user(), $case, $document, $request->validated()))));
    }

    public function download(Request $request, int $case, int $document, int $version, CaseDocumentStorage $storage, AuditWriter $audit): Response
    {
        return $this->file($request, $case, $document, $version, $storage, $audit, false);
    }

    public function preview(Request $request, int $case, int $document, int $version, CaseDocumentStorage $storage, AuditWriter $audit): Response
    {
        return $this->file($request, $case, $document, $version, $storage, $audit, true);
    }

    private function file(Request $request, int $case, int $document, int $version, CaseDocumentStorage $storage, AuditWriter $audit, bool $preview): Response
    {
        $row = $this->owned($request, $case)->documents()->whereNull('deleted_at')->findOrFail($document)->versions()->findOrFail($version);
        if ($row->scan_status !== CaseDocumentScanStatus::Clean || ($preview && ! in_array($row->mime_type, ['image/jpeg', 'image/png'], true))) {
            throw new PrivacyException('DOCUMENT_NOT_READY', 'This document is not available for the requested operation.');
        }
        $bytes = $storage->bytes($row);
        $audit->write($request->user(), $preview ? 'case.document_previewed' : 'case.document_downloaded', 'case', $case);

        return response($bytes, 200, ['Content-Type' => $row->mime_type, 'Content-Disposition' => ($preview ? 'inline' : 'attachment').'; filename="case-document-'.$row->id.'.'.$row->extension.'"', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store']);
    }

    private function owned(Request $request, int $id): CaseRecord
    {
        $row = $request->user()->cases()->findOrFail($id);
        Gate::authorize('view', $row);

        return $row;
    }
}
