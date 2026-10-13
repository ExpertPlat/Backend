<?php

namespace App\Services\Kyc;

use App\Enums\ExpertKycApplicationStatus;
use App\Enums\ExpertKycDocumentType;
use App\Exceptions\InvalidKycTransitionException;
use App\Models\Admin;
use App\Models\ExpertKycApplication;
use App\Models\ExpertKycDocument;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpertKycDocumentStorage
{
    public function store(
        ExpertKycApplication $application,
        ExpertKycDocumentType $type,
        UploadedFile $file,
        ?int $qualificationId = null,
        ?int $credentialId = null,
    ): ExpertKycDocument {
        if ($application->status !== ExpertKycApplicationStatus::Draft) {
            throw new InvalidKycTransitionException('Documents can only be changed while the KYC application is a draft.');
        }

        $this->validateRelatedRecord($application, $type, $qualificationId, $credentialId);

        if ($type === ExpertKycDocumentType::WorkSample) {
            $count = $application->documents()->where('document_type', $type->value)->count();

            if ($count >= config('kyc.documents.max_work_samples')) {
                throw ValidationException::withMessages([
                    'file' => ['The maximum number of work samples has been reached.'],
                ]);
            }
        }

        $disk = (string) config('kyc.disk');
        $extension = mb_strtolower($file->extension());
        $path = 'applications/'.$application->reference.'/'.Str::uuid().'.'.$extension;

        Storage::disk($disk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        try {
            return DB::transaction(function () use (
                $application,
                $type,
                $file,
                $qualificationId,
                $credentialId,
                $disk,
                $path,
                $extension,
            ): ExpertKycDocument {
                $lockedApplication = ExpertKycApplication::query()
                    ->lockForUpdate()
                    ->findOrFail($application->getKey());

                if ($lockedApplication->status !== ExpertKycApplicationStatus::Draft) {
                    throw new InvalidKycTransitionException('Documents can only be changed while the KYC application is a draft.');
                }

                $this->validateRelatedRecord($lockedApplication, $type, $qualificationId, $credentialId);

                if ($type === ExpertKycDocumentType::WorkSample
                    && $lockedApplication->documents()->where('document_type', $type->value)->count()
                        >= config('kyc.documents.max_work_samples')) {
                    throw ValidationException::withMessages([
                        'file' => ['The maximum number of work samples has been reached.'],
                    ]);
                }

                $replaceable = $this->replaceableDocuments(
                    $lockedApplication,
                    $type,
                    $qualificationId,
                    $credentialId,
                )->get();

                $sortOrder = $type === ExpertKycDocumentType::WorkSample
                    ? ((int) $lockedApplication->documents()->where('document_type', $type->value)->max('sort_order')) + 1
                    : 0;

                $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName())));

                $document = $lockedApplication->documents()->create([
                    'document_type' => $type,
                    'qualification_id' => $qualificationId,
                    'credential_id' => $credentialId,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => mb_substr($originalName ?: 'document.'.$extension, 0, 255),
                    'extension' => $extension,
                    'mime_type' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                    'checksum' => hash_file('sha256', $file->getRealPath()),
                    'sort_order' => $sortOrder,
                ]);

                if ($replaceable->isNotEmpty()) {
                    $this->deleteRecords($replaceable);
                }

                return $document;
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    public function delete(ExpertKycDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $lockedApplication = ExpertKycApplication::query()
                ->lockForUpdate()
                ->findOrFail($document->application_id);

            if ($lockedApplication->status !== ExpertKycApplicationStatus::Draft) {
                throw new InvalidKycTransitionException('Documents can only be changed while the KYC application is a draft.');
            }

            $lockedDocument = ExpertKycDocument::query()
                ->lockForUpdate()
                ->findOrFail($document->getKey());

            $this->deleteRecords(new Collection([$lockedDocument]));
        });
    }

    /**
     * @param  Collection<int, ExpertKycDocument>  $documents
     */
    public function deleteRecords(Collection $documents): void
    {
        $paths = $documents
            ->map(fn (ExpertKycDocument $document): array => [$document->disk, $document->path])
            ->unique(fn (array $item): string => $item[0].'|'.$item[1])
            ->values();

        ExpertKycDocument::query()->whereKey($documents->modelKeys())->delete();

        DB::afterCommit(function () use ($paths): void {
            foreach ($paths as [$disk, $path]) {
                $stillReferenced = ExpertKycDocument::query()
                    ->where('disk', $disk)
                    ->where('path', $path)
                    ->exists();

                if (! $stillReferenced) {
                    Storage::disk($disk)->delete($path);
                }
            }
        });
    }

    public function setReviewed(ExpertKycDocument $document, Admin $admin, bool $reviewed): ExpertKycDocument
    {
        return DB::transaction(function () use ($document, $admin, $reviewed): ExpertKycDocument {
            $application = ExpertKycApplication::query()
                ->lockForUpdate()
                ->findOrFail($document->application_id);

            if ($application->status !== ExpertKycApplicationStatus::UnderReview) {
                throw new InvalidKycTransitionException('Documents can only be reviewed while the application is under review.');
            }

            $lockedDocument = ExpertKycDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            $lockedDocument->forceFill([
                'reviewed_at' => $reviewed ? now() : null,
                'reviewed_by_admin_id' => $reviewed ? $admin->getKey() : null,
            ])->save();

            return $lockedDocument->refresh();
        });
    }

    private function validateRelatedRecord(
        ExpertKycApplication $application,
        ExpertKycDocumentType $type,
        ?int $qualificationId,
        ?int $credentialId,
    ): void {
        if ($type === ExpertKycDocumentType::Qualification) {
            $valid = $qualificationId !== null
                && $application->qualifications()->whereKey($qualificationId)->exists();

            if (! $valid) {
                throw ValidationException::withMessages([
                    'qualificationId' => ['A qualification belonging to this KYC application is required.'],
                ]);
            }
        }

        if ($type === ExpertKycDocumentType::Credential) {
            $valid = $credentialId !== null
                && $application->credentials()->whereKey($credentialId)->exists();

            if (! $valid) {
                throw ValidationException::withMessages([
                    'credentialId' => ['A credential belonging to this KYC application is required.'],
                ]);
            }
        }

        if (! in_array($type, [ExpertKycDocumentType::Qualification, ExpertKycDocumentType::Credential], true)
            && ($qualificationId !== null || $credentialId !== null)) {
            throw ValidationException::withMessages([
                'documentType' => ['Related record IDs are not valid for this document type.'],
            ]);
        }
    }

    private function replaceableDocuments(
        ExpertKycApplication $application,
        ExpertKycDocumentType $type,
        ?int $qualificationId,
        ?int $credentialId,
    ) {
        $query = $application->documents()->where('document_type', $type->value);

        return match ($type) {
            ExpertKycDocumentType::Identity,
            ExpertKycDocumentType::Cv => $query,
            ExpertKycDocumentType::Qualification => $query->where('qualification_id', $qualificationId),
            ExpertKycDocumentType::Credential => $query->where('credential_id', $credentialId),
            ExpertKycDocumentType::WorkSample => $query->whereRaw('1 = 0'),
        };
    }
}
