<?php

namespace App\Services\Kyc;

use App\Enums\ExpertKycActorType;
use App\Enums\ExpertKycApplicationStatus;
use App\Enums\ExpertKycDocumentType;
use App\Enums\ExpertKycStatus;
use App\Enums\ExpertScopeStatus;
use App\Exceptions\InvalidKycTransitionException;
use App\Models\Admin;
use App\Models\CatalogVersion;
use App\Models\Expert;
use App\Models\ExpertKycApplication;
use App\Models\ExpertKycCredential;
use App\Models\ExpertKycQualification;
use App\Notifications\Expert\KycReviewStatusNotification;
use App\Services\Catalog\CatalogTaxonomy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpertKycWorkflow
{
    public function __construct(private readonly ExpertKycDocumentStorage $documents) {}

    /**
     * @return array<string, mixed>
     */
    public function prefill(Expert $expert): array
    {
        return [
            'fullName' => $expert->name,
            'email' => $expert->email,
            'country' => $expert->country,
            'language' => $expert->language,
            'domain' => $expert->domain,
            'jurisdiction' => null,
            'payoutReadiness' => 'not_ready',
            'experiences' => [],
            'qualifications' => [],
            'credentials' => [],
            'documents' => [],
        ];
    }

    public function saveDraft(Expert $expert, array $data): ExpertKycApplication
    {
        return DB::transaction(function () use ($expert, $data): ExpertKycApplication {
            $lockedExpert = Expert::query()->lockForUpdate()->findOrFail($expert->getKey());
            $latest = $lockedExpert->kycApplications()->latest('attempt_number')->lockForUpdate()->first();

            if ($latest?->status === ExpertKycApplicationStatus::Verified) {
                throw new InvalidKycTransitionException('A verified KYC application cannot be changed.');
            }

            if ($latest && $latest->status->canStartNewAttempt()) {
                $latest = $this->cloneAttempt($latest, $lockedExpert);
            }

            if ($latest && $latest->status !== ExpertKycApplicationStatus::Draft) {
                throw new InvalidKycTransitionException('This KYC application cannot be edited while it is being reviewed.');
            }

            $application = $latest ?? $this->createDraft($lockedExpert);

            $application->fill([
                'full_name' => array_key_exists('fullName', $data) ? $data['fullName'] : $application->full_name,
                'email_snapshot' => $lockedExpert->email,
                'country' => array_key_exists('country', $data) ? $data['country'] : $application->country,
                'language' => array_key_exists('language', $data) ? $data['language'] : $application->language,
                'domain' => array_key_exists('domain', $data) ? $data['domain'] : $application->domain,
                'jurisdiction' => array_key_exists('jurisdiction', $data) ? $data['jurisdiction'] : $application->jurisdiction,
            ])->save();

            if (array_key_exists('experiences', $data)) {
                $this->syncExperiences($application, $data['experiences']);
            }

            if (array_key_exists('qualifications', $data)) {
                $this->syncQualifications($application, $data['qualifications']);
            }

            if (array_key_exists('credentials', $data)) {
                $this->syncCredentials($application, $data['credentials']);
            }

            return $this->loadApplication($application->refresh());
        });
    }

    public function submit(Expert $expert): ExpertKycApplication
    {
        return DB::transaction(function () use ($expert): ExpertKycApplication {
            $application = $expert->kycApplications()->latest('attempt_number')->lockForUpdate()->first();

            if (! $application || $application->status !== ExpertKycApplicationStatus::Draft) {
                throw new InvalidKycTransitionException('Only a draft KYC application can be submitted.');
            }

            $application->load(['experiences', 'documents']);
            $this->ensureComplete($application);
            $this->transition($application, ExpertKycApplicationStatus::Submitted, ExpertKycActorType::Expert, $expert->getKey());
            $application->forceFill(['submitted_at' => now()])->save();
            $expert->forceFill(['kyc_status' => ExpertKycStatus::Pending])->save();

            return $this->loadApplication($application->refresh());
        });
    }

    public function startReview(ExpertKycApplication $application, Admin $admin): ExpertKycApplication
    {
        return $this->adminTransition($application, $admin, ExpertKycApplicationStatus::Submitted, ExpertKycApplicationStatus::UnderReview);
    }

    public function approve(
        ExpertKycApplication $application,
        Admin $admin,
        array $scopes = [],
    ): ExpertKycApplication {
        $result = DB::transaction(function () use ($application, $admin, $scopes): ExpertKycApplication {
            $locked = ExpertKycApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $this->assertStatus($locked, ExpertKycApplicationStatus::UnderReview);
            $locked->load(['experiences', 'qualifications.document', 'credentials.document', 'documents']);
            $this->ensureComplete($locked);

            if ($locked->documents->contains(fn ($document): bool => $document->reviewed_at === null)) {
                throw ValidationException::withMessages([
                    'documents' => ['Every submitted document must be reviewed before approval.'],
                ]);
            }

            $verifiedScopes = $this->verifiedScopeEvidence($locked, $scopes);

            $this->transition($locked, ExpertKycApplicationStatus::Verified, ExpertKycActorType::Admin, $admin->getKey());
            $locked->forceFill([
                'reviewed_by_admin_id' => $admin->getKey(),
                'decided_at' => now(),
                'decision_reason' => null,
            ])->save();
            $locked->expert()->update(['kyc_status' => ExpertKycStatus::Approved]);
            $this->replaceVerifiedScopes($locked, $admin, $verifiedScopes);

            return $this->loadApplication($locked->refresh());
        });

        $this->notifyExpert($result);

        return $result;
    }

    public function reject(ExpertKycApplication $application, Admin $admin, string $reason): ExpertKycApplication
    {
        return $this->decide($application, $admin, ExpertKycApplicationStatus::Rejected, $reason, ExpertKycStatus::Rejected);
    }

    public function requestInformation(
        ExpertKycApplication $application,
        Admin $admin,
        string $reason,
        array $requestedChanges,
    ): ExpertKycApplication {
        return $this->decide(
            $application,
            $admin,
            ExpertKycApplicationStatus::NeedsInformation,
            $reason,
            ExpertKycStatus::Pending,
            $requestedChanges,
        );
    }

    public function loadApplication(ExpertKycApplication $application, bool $withHistory = false): ExpertKycApplication
    {
        $relations = [
            'expert:id,name,email,country,language,domain,is_active,kyc_status',
            'reviewedBy:id,name,email',
            'sourceApplication:id,reference,attempt_number,status,decision_reason,requested_changes,decided_at',
            'verifiedScopes',
            'experiences',
            'qualifications.document',
            'credentials.document',
            'documents.reviewedBy:id,name',
        ];

        if ($withHistory) {
            $relations[] = 'statusHistories';
        }

        return $application->load($relations);
    }

    private function createDraft(Expert $expert): ExpertKycApplication
    {
        $application = $expert->kycApplications()->create([
            'reference' => $this->reference(),
            'attempt_number' => 1,
            'full_name' => $expert->name,
            'email_snapshot' => $expert->email,
            'country' => $expert->country,
            'language' => $expert->language,
            'domain' => $expert->domain,
            'payout_readiness' => 'not_ready',
        ]);

        $this->recordHistory($application, null, ExpertKycApplicationStatus::Draft, ExpertKycActorType::Expert, $expert->getKey());

        return $application;
    }

    private function cloneAttempt(ExpertKycApplication $source, Expert $expert): ExpertKycApplication
    {
        $source->load(['experiences', 'qualifications', 'credentials', 'documents']);

        $target = $expert->kycApplications()->create([
            'reference' => $this->reference(),
            'attempt_number' => $source->attempt_number + 1,
            'source_application_id' => $source->getKey(),
            'full_name' => $source->full_name,
            'email_snapshot' => $expert->email,
            'country' => $source->country,
            'language' => $source->language,
            'domain' => $source->domain,
            'jurisdiction' => $source->jurisdiction,
            'payout_readiness' => $source->payout_readiness,
        ]);

        foreach ($source->experiences as $item) {
            $target->experiences()->create($item->only([
                'job_title', 'organization', 'from_month', 'to_month', 'is_current', 'description', 'sort_order',
            ]));
        }

        $qualificationMap = [];
        foreach ($source->qualifications as $item) {
            $copy = $target->qualifications()->create($item->only([
                'degree', 'field', 'institution', 'graduation_year', 'sort_order',
            ]));
            $qualificationMap[$item->getKey()] = $copy->getKey();
        }

        $credentialMap = [];
        foreach ($source->credentials as $item) {
            $copy = $target->credentials()->create($item->only([
                'type', 'name', 'issuer', 'issue_date', 'expiry_date', 'sort_order',
            ]));
            $credentialMap[$item->getKey()] = $copy->getKey();
        }

        foreach ($source->documents as $document) {
            $target->documents()->create([
                ...$document->only([
                    'document_type', 'disk', 'path', 'original_name', 'extension', 'mime_type', 'size', 'checksum', 'sort_order',
                ]),
                'qualification_id' => $document->qualification_id ? $qualificationMap[$document->qualification_id] : null,
                'credential_id' => $document->credential_id ? $credentialMap[$document->credential_id] : null,
            ]);
        }

        $this->recordHistory(
            $target,
            null,
            ExpertKycApplicationStatus::Draft,
            ExpertKycActorType::Expert,
            $expert->getKey(),
            null,
            ['source_application_id' => $source->getKey()],
        );

        return $target->refresh();
    }

    private function syncExperiences(ExpertKycApplication $application, array $items): void
    {
        $this->syncChildren(
            $application,
            $application->experiences()->get(),
            $items,
            'experiences',
            fn (array $item, int $order): array => [
                'job_title' => $item['jobTitle'],
                'organization' => $item['organization'],
                'from_month' => $item['from'] ?? null,
                'to_month' => ($item['current'] ?? false) ? null : ($item['to'] ?? null),
                'is_current' => $item['current'] ?? false,
                'description' => $item['description'] ?? null,
                'sort_order' => $order,
            ],
            fn (array $attributes) => $application->experiences()->create($attributes),
        );
    }

    private function syncQualifications(ExpertKycApplication $application, array $items): void
    {
        $this->syncChildren(
            $application,
            $application->qualifications()->get(),
            $items,
            'qualifications',
            fn (array $item, int $order): array => [
                'degree' => $item['degree'],
                'field' => $item['field'] ?? null,
                'institution' => $item['institution'],
                'graduation_year' => $item['graduationYear'] ?? null,
                'sort_order' => $order,
            ],
            fn (array $attributes) => $application->qualifications()->create($attributes),
        );
    }

    private function syncCredentials(ExpertKycApplication $application, array $items): void
    {
        $this->syncChildren(
            $application,
            $application->credentials()->get(),
            $items,
            'credentials',
            fn (array $item, int $order): array => [
                'type' => $item['type'],
                'name' => $item['name'],
                'issuer' => $item['issuer'],
                'issue_date' => $item['issueDate'] ?? null,
                'expiry_date' => $item['expiryDate'] ?? null,
                'sort_order' => $order,
            ],
            fn (array $attributes) => $application->credentials()->create($attributes),
        );
    }

    private function syncChildren(
        ExpertKycApplication $application,
        Collection $existing,
        array $items,
        string $relation,
        callable $attributes,
        callable $create,
    ): void {
        $keptIds = [];

        foreach (array_values($items) as $order => $item) {
            $itemAttributes = $attributes($item, $order);
            $id = $item['id'] ?? null;

            if ($id !== null) {
                $model = $this->resolveEditableChild(
                    $application,
                    $existing,
                    (int) $id,
                    $relation,
                );

                if (! $model) {
                    throw ValidationException::withMessages([
                        'items' => ['One of the submitted records does not belong to this KYC application.'],
                    ]);
                }

                $model->update($itemAttributes);
                $keptIds[] = $model->getKey();
            } else {
                $model = $create($itemAttributes);
                $keptIds[] = $model->getKey();
            }
        }

        $removed = $existing->whereNotIn('id', $keptIds);
        foreach ($removed as $model) {
            $linkedDocuments = $application->documents()
                ->where(function ($query) use ($model): void {
                    if ($model instanceof ExpertKycQualification) {
                        $query->where('qualification_id', $model->getKey());
                    } elseif ($model instanceof ExpertKycCredential) {
                        $query->where('credential_id', $model->getKey());
                    } else {
                        $query->whereRaw('1 = 0');
                    }
                })
                ->get();

            if ($linkedDocuments->isNotEmpty()) {
                $this->documents->deleteRecords($linkedDocuments);
            }

            $model->delete();
        }
    }

    private function resolveEditableChild(
        ExpertKycApplication $application,
        Collection $existing,
        int $submittedId,
        string $relation,
    ): ?Model {
        $current = $existing->firstWhere('id', $submittedId);

        if ($current || ! $application->source_application_id) {
            return $current;
        }

        $sourceApplication = $application->sourceApplication()->first();
        if (! $sourceApplication) {
            return null;
        }

        $source = $sourceApplication->{$relation}()->whereKey($submittedId)->first();

        if (! $source) {
            return null;
        }

        return $existing->first(
            fn ($candidate): bool => (int) $candidate->sort_order === (int) $source->sort_order,
        );
    }

    private function ensureComplete(ExpertKycApplication $application): void
    {
        $errors = [];

        $requiredFields = [
            'full_name' => 'fullName',
            'country' => 'country',
            'language' => 'language',
            'domain' => 'domain',
            'jurisdiction' => 'jurisdiction',
        ];

        foreach ($requiredFields as $field => $responseKey) {
            if (blank($application->{$field})) {
                $errors[$responseKey] = ['This field is required before submission.'];
            }
        }

        $hasIdentity = $application->documents->contains(
            fn ($document): bool => $document->document_type === ExpertKycDocumentType::Identity,
        );
        $hasCv = $application->documents->contains(
            fn ($document): bool => $document->document_type === ExpertKycDocumentType::Cv,
        );

        if (! $hasIdentity) {
            $errors['identityEvidence'] = ['Identity evidence is required before submission.'];
        }

        if (! $hasCv && $application->experiences->isEmpty()) {
            $errors['cv'] = ['A CV or at least one complete experience record is required before submission.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function adminTransition(
        ExpertKycApplication $application,
        Admin $admin,
        ExpertKycApplicationStatus $from,
        ExpertKycApplicationStatus $to,
    ): ExpertKycApplication {
        return DB::transaction(function () use ($application, $admin, $from, $to): ExpertKycApplication {
            $locked = ExpertKycApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $this->assertStatus($locked, $from);
            $this->transition($locked, $to, ExpertKycActorType::Admin, $admin->getKey());
            $locked->forceFill([
                'review_started_at' => now(),
                'reviewed_by_admin_id' => $admin->getKey(),
            ])->save();

            return $this->loadApplication($locked->refresh(), true);
        });
    }

    private function decide(
        ExpertKycApplication $application,
        Admin $admin,
        ExpertKycApplicationStatus $to,
        string $reason,
        ExpertKycStatus $expertStatus,
        array $requestedChanges = [],
    ): ExpertKycApplication {
        $result = DB::transaction(function () use (
            $application,
            $admin,
            $to,
            $reason,
            $expertStatus,
            $requestedChanges,
        ): ExpertKycApplication {
            $locked = ExpertKycApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $this->assertStatus($locked, ExpertKycApplicationStatus::UnderReview);
            $normalizedChanges = $to === ExpertKycApplicationStatus::NeedsInformation
                ? $this->normalizeRequestedChanges($locked, $requestedChanges)
                : null;
            $this->transition($locked, $to, ExpertKycActorType::Admin, $admin->getKey(), $reason);
            $locked->forceFill([
                'reviewed_by_admin_id' => $admin->getKey(),
                'decided_at' => now(),
                'decision_reason' => $reason,
                'requested_changes' => $normalizedChanges,
            ])->save();
            $locked->expert()->update(['kyc_status' => $expertStatus]);

            return $this->loadApplication($locked->refresh(), true);
        });

        $this->notifyExpert($result);

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $requestedChanges
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRequestedChanges(
        ExpertKycApplication $application,
        array $requestedChanges,
    ): array {
        $documentIds = collect($requestedChanges)
            ->pluck('documentId')
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($documentIds->isNotEmpty()) {
            $ownedCount = $application->documents()->whereKey($documentIds)->count();

            if ($ownedCount !== $documentIds->count()) {
                throw ValidationException::withMessages([
                    'requestedChanges' => ['Every referenced document must belong to this KYC application.'],
                ]);
            }
        }

        return array_values(array_map(static fn (array $change): array => [
            'section' => $change['section'],
            'field' => $change['field'] ?? null,
            'document_id' => isset($change['documentId']) ? (int) $change['documentId'] : null,
            'message' => $change['message'],
        ], $requestedChanges));
    }

    private function notifyExpert(ExpertKycApplication $application): void
    {
        $application->expert->notify(new KycReviewStatusNotification(
            $application->reference,
            $application->status,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $scopes
     */
    private function replaceVerifiedScopes(
        ExpertKycApplication $application,
        Admin $admin,
        array $scopes,
    ): void {
        $application->expert->verifiedScopes()
            ->where('status', ExpertScopeStatus::Active->value)
            ->where('domain', $application->domain)
            ->update(['status' => ExpertScopeStatus::Revoked->value]);

        foreach ($scopes as $scope) {
            $application->expert->verifiedScopes()->create([
                'kyc_application_id' => $application->getKey(),
                'verified_by_admin_id' => $admin->getKey(),
                'domain' => $scope['domain'],
                'jurisdiction' => $scope['jurisdiction'],
                'role' => $scope['role'],
                'service_types' => array_values($scope['serviceTypes']),
                'languages' => array_values($scope['languages']),
                'status' => ExpertScopeStatus::Active,
                'valid_from' => today(),
                'valid_until' => $scope['validUntil'] ?? null,
                'evidence_type' => $scope['evidence']['type'],
                'evidence_id' => $scope['evidence']['id'],
                'evidence_document_id' => $scope['evidenceDocumentId'],
                'verified_country' => $scope['jurisdictionCountry'] ?? $application->country,
                'regulator' => $scope['professionalReview']['regulator'] ?? null,
                'registration_number' => $scope['professionalReview']['registrationNumber'] ?? null,
                'verification_source' => $scope['professionalReview']['verificationSource'] ?? null,
                'status_checked' => $scope['professionalReview']['statusChecked'] ?? null,
                'checked_at' => now(),
                'next_review_at' => $scope['professionalReview']['nextReviewAt'] ?? null,
            ]);
        }
    }

    /**
     * Validate the reviewer-selected evidence again inside the locked approval
     * transaction. FormRequest validation alone cannot protect direct callers.
     *
     * @param  array<int, array<string, mixed>>  $scopes
     * @return array<int, array<string, mixed>>
     */
    public function verifiedScopeEvidence(ExpertKycApplication $application, array $scopes): array
    {
        if ($scopes === []) {
            throw ValidationException::withMessages([
                'scopes' => ['At least one evidence-backed scope is required for approval.'],
            ]);
        }

        $validated = [];

        foreach ($scopes as $index => $scope) {
            $prefix = "scopes.{$index}";
            $domain = mb_strtolower((string) ($scope['domain'] ?? ''));

            if ($domain !== mb_strtolower((string) $application->domain)
                || ($scope['jurisdiction'] ?? null) !== $application->jurisdiction) {
                throw ValidationException::withMessages([
                    $prefix => ['A verified scope must match the reviewed domain and jurisdiction.'],
                ]);
            }

            $regulated = in_array($domain, CatalogTaxonomy::domains(true), true) || (($scope['professionalReview']['statusChecked'] ?? null) === 'active');
            $unregulated = in_array($domain, CatalogTaxonomy::domains(false), true);
            if (isset($scope['catalogPolicyVersionId'])) {
                $policy = CatalogVersion::find($scope['catalogPolicyVersionId']);
                if (! $policy || ! $policy->reviewed_at || $policy->policy['domain'] !== $domain) {
                    throw ValidationException::withMessages(["$prefix.catalogPolicyVersionId" => 'Select a reviewed policy for this domain.']);
                }
                $regulated = $regulated || $policy->policy['regulated'] || $policy->policy['requiresProfessionalLicense'];
                $unregulated = ! $regulated && $unregulated;
            }

            if (! $regulated && ! $unregulated) {
                throw ValidationException::withMessages([
                    "$prefix.domain" => ['This professional domain has no approved verification policy.'],
                ]);
            }

            $type = $scope['evidence']['type'] ?? null;
            $id = (int) ($scope['evidence']['id'] ?? 0);
            $record = match ($type) {
                'credential' => $application->credentials->firstWhere('id', $id),
                'qualification' => $application->qualifications->firstWhere('id', $id),
                'experience' => $application->experiences->firstWhere('id', $id),
                default => null,
            };

            if (! $record) {
                throw ValidationException::withMessages([
                    "$prefix.evidence.id" => ['Evidence must belong to this KYC application.'],
                ]);
            }

            $document = $type === 'experience'
                ? $application->documents->first(fn ($item): bool => in_array($item->document_type, [
                    ExpertKycDocumentType::Cv, ExpertKycDocumentType::WorkSample,
                ], true) && $item->reviewed_at !== null)
                : $record->document;

            if (! $document || (int) $document->application_id !== (int) $application->getKey()
                || $document->reviewed_at === null) {
                throw ValidationException::withMessages([
                    "$prefix.evidence" => ['Selected evidence needs a reviewed document from this application.'],
                ]);
            }

            $scope['evidenceDocumentId'] = $document->getKey();

            if ($type === 'credential' && $record->expiry_date?->isPast()) {
                throw ValidationException::withMessages([
                    "$prefix.evidence.id" => ['An expired credential cannot support an active scope.'],
                ]);
            }

            if ($regulated) {
                $review = $scope['professionalReview'] ?? [];

                if ($type !== 'credential' || $record->type !== 'license') {
                    throw ValidationException::withMessages([
                        "$prefix.evidence" => ['A regulated scope requires a reviewed professional licence.'],
                    ]);
                }

                if (blank($scope['jurisdictionCountry'] ?? null)) {
                    throw ValidationException::withMessages([
                        "$prefix.jurisdictionCountry" => ['A regulated scope must name its licensed country.'],
                    ]);
                }

                foreach (['verifiedCountry', 'regulator', 'registrationNumber', 'verificationSource', 'statusChecked', 'nextReviewAt'] as $field) {
                    if (blank($review[$field] ?? null)) {
                        throw ValidationException::withMessages([
                            "$prefix.professionalReview.$field" => ['An authoritative licence review is required.'],
                        ]);
                    }
                }

                if (mb_strtoupper((string) $review['verifiedCountry']) !== mb_strtoupper((string) $scope['jurisdictionCountry'])) {
                    throw ValidationException::withMessages([
                        "$prefix.professionalReview.verifiedCountry" => ['The verified country must match the scope jurisdiction country.'],
                    ]);
                }

                $scope['jurisdictionCountry'] = mb_strtoupper((string) $scope['jurisdictionCountry']);

                if ($review['statusChecked'] !== 'active'
                    || ! str_starts_with((string) $review['verificationSource'], 'https://')) {
                    throw ValidationException::withMessages([
                        "$prefix.professionalReview" => ['An active licence and an HTTPS authoritative source are required.'],
                    ]);
                }

                $recheck = Carbon::parse($review['nextReviewAt']);

                if ($recheck->isToday() || $recheck->isPast() || $recheck->gt(today()->addYear())) {
                    throw ValidationException::withMessages([
                        "$prefix.professionalReview.nextReviewAt" => ['The next review must be within one year.'],
                    ]);
                }

                $latestValidity = $record->expiry_date && $record->expiry_date->lt($recheck)
                    ? $record->expiry_date
                    : $recheck;

                if (isset($scope['validUntil']) && Carbon::parse($scope['validUntil'])->gt($latestValidity)) {
                    throw ValidationException::withMessages([
                        "$prefix.validUntil" => ['Scope validity cannot outlast licence expiry or the next review.'],
                    ]);
                }

                $scope['validUntil'] ??= $latestValidity->toDateString();
            } elseif ($type === 'credential' && $record->expiry_date) {
                if (isset($scope['validUntil']) && Carbon::parse($scope['validUntil'])->gt($record->expiry_date)) {
                    throw ValidationException::withMessages([
                        "$prefix.validUntil" => ['Scope validity cannot outlast the selected credential.'],
                    ]);
                }

                $scope['validUntil'] ??= $record->expiry_date->toDateString();
            }

            if (! $regulated) {
                $scope['professionalReview'] = [];
            }

            $validated[] = $scope;
        }

        return $validated;
    }

    private function transition(
        ExpertKycApplication $application,
        ExpertKycApplicationStatus $to,
        ExpertKycActorType $actorType,
        ?int $actorId,
        ?string $reason = null,
    ): void {
        $from = $application->status;
        $application->forceFill(['status' => $to])->save();
        $this->recordHistory($application, $from, $to, $actorType, $actorId, $reason);
    }

    private function recordHistory(
        ExpertKycApplication $application,
        ?ExpertKycApplicationStatus $from,
        ExpertKycApplicationStatus $to,
        ExpertKycActorType $actorType,
        ?int $actorId,
        ?string $reason = null,
        ?array $metadata = null,
    ): void {
        $application->statusHistories()->create([
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }

    private function assertStatus(ExpertKycApplication $application, ExpertKycApplicationStatus $expected): void
    {
        if ($application->status !== $expected) {
            throw new InvalidKycTransitionException(
                "The KYC application must be {$expected->value} before this action can be performed.",
            );
        }
    }

    private function reference(): string
    {
        do {
            $reference = 'KYC-'.now()->format('Y').'-'.Str::upper(Str::random(10));
        } while (ExpertKycApplication::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
