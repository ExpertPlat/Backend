<?php

namespace App\Services\Expert\Renewal;

use App\Enums\ExpertKycStatus;
use App\Enums\ExpertRenewalStatus as State;
use App\Enums\ExpertScopeStatus;
use App\Exceptions\PrivacyException;
use App\Models\Admin;
use App\Models\Expert;
use App\Models\ExpertCatalogGrant;
use App\Models\ExpertScopeRenewal;
use App\Models\ExpertScopeRenewalSubmission;
use App\Models\ExpertVerifiedScope;
use App\Services\Catalog\CatalogTaxonomy;
use App\Services\Kyc\ExpertKycWorkflow;
use App\Services\Privacy\AuditWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScopeRenewal
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function eligibility(ExpertVerifiedScope $scope, Expert $expert, ?int $openId = null): array
    {
        $due = collect([$scope->valid_until, $scope->next_review_at])->filter()->sort()->first();
        $opens = $due?->copy()->subDays((int) config('expert_renewal.window_days'));
        $reason = match (true) {
            ! $expert->is_active || $expert->kyc_status !== ExpertKycStatus::Approved => 'EXPERT_NOT_APPROVED',
            ! in_array($scope->status, [ExpertScopeStatus::Active, ExpertScopeStatus::Expired], true) => 'SCOPE_NOT_RENEWABLE',
            ! $scope->verified_country => 'LEGACY_REVIEW_REQUIRED',
            ! in_array($scope->domain, array_merge(CatalogTaxonomy::domains(true), CatalogTaxonomy::domains(false)), true) => 'DOMAIN_POLICY_REQUIRED',
            $openId !== null => 'OPEN_RENEWAL_EXISTS',
            $due === null => 'REVIEW_DATE_REQUIRED',
            $opens->gt(today()) => 'RENEWAL_WINDOW_NOT_OPEN',
            default => null,
        };

        return ['eligible' => $reason === null, 'reason' => $reason, 'opensAt' => $opens?->toDateString(), 'dueAt' => $due?->toDateString(), 'openRequestId' => $openId];
    }

    public function create(Expert $actor, int $scopeId): ExpertScopeRenewal
    {
        return DB::transaction(function () use ($actor, $scopeId) {
            $expert = Expert::query()->lockForUpdate()->findOrFail($actor->id);
            $scope = $expert->verifiedScopes()->lockForUpdate()->findOrFail($scopeId);
            $open = ExpertScopeRenewal::query()->where('open_scope_id', $scope->id)->value('id');
            $eligibility = $this->eligibility($scope, $expert, $open);
            if (! $eligibility['eligible']) {
                throw new PrivacyException($eligibility['reason'], 'This scope cannot open a renewal request at this time.');
            }
            $row = new ExpertScopeRenewal;
            $row->forceFill(['expert_id' => $expert->id, 'scope_id' => $scope->id, 'open_scope_id' => $scope->id, 'status' => State::Draft, 'version' => 1, 'history' => []])->save();
            $this->audit->write($actor, 'expert_renewal.created', 'expert_scope_renewal', $row->id, null, State::Draft->value);

            return $row;
        });
    }

    public function mutate(Expert|Admin $actor, ExpertScopeRenewal $original, int $version, string $action, array $data = [], ?UploadedFile $file = null): ExpertScopeRenewal
    {
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $original, $version, $action, $data, $file, &$stored) {
                $expert = Expert::query()->lockForUpdate()->findOrFail($original->expert_id);
                $scope = ExpertVerifiedScope::query()->lockForUpdate()->findOrFail($original->scope_id);
                $row = ExpertScopeRenewal::query()->lockForUpdate()->findOrFail($original->id);
                if ($actor instanceof Expert && $actor->id !== $row->expert_id) {
                    abort(404);
                }
                if ($row->version !== $version) {
                    throw new PrivacyException('STALE_VERSION', 'Refresh the request before retrying.');
                }
                $allowed = match ($action) {
                    'evidence', 'submit' => [State::Draft, State::NeedsInformation],
                    'cancel' => [State::Draft, State::Submitted, State::NeedsInformation],
                    'startReview' => [State::Submitted],
                    'approve', 'reject', 'requestInformation' => [State::UnderReview],
                    default => [],
                };
                if (! in_array($row->status, $allowed, true)) {
                    throw new PrivacyException('INVALID_TRANSITION', 'This action is unavailable in the current state.');
                }
                if (($actor instanceof Expert) !== in_array($action, ['evidence', 'submit', 'cancel'], true)) {
                    abort(403);
                }
                if (in_array($action, ['submit', 'approve'], true)) {
                    $check = $this->eligibility($scope, $expert);
                    if (! $check['eligible']) {
                        throw new PrivacyException($check['reason'], 'Scope eligibility changed; renewal cannot continue.');
                    }
                }
                $from = $row->status;
                if ($action === 'evidence') {
                    $sequence = $row->submissions()->count() + 1;
                    if ($sequence > config('expert_renewal.max_submissions')) {
                        throw new PrivacyException('SUBMISSION_LIMIT', 'The evidence revision limit has been reached.');
                    }
                    $evidence = $data['evidence'];
                    if ($this->regulated($scope) && ($evidence['type'] !== 'credential' || ($evidence['credentialType'] ?? null) !== 'license')) {
                        throw ValidationException::withMessages(['evidence' => ['A professional license is required for this domain.']]);
                    }
                    $disk = config('kyc.disk');
                    $path = 'renewals/'.$row->id.'/'.Str::uuid().'.'.$file->extension();
                    $stored = [$disk, $path];
                    if (! Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()), ['visibility' => 'private'])) {
                        throw new PrivacyException('EVIDENCE_STORAGE_FAILED', 'Evidence could not be stored.');
                    }
                    $submission = new ExpertScopeRenewalSubmission;
                    $submission->forceFill(['renewal_id' => $row->id, 'sequence' => $sequence, 'evidence' => $evidence,
                        'disk' => $disk, 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath())])->save();
                    $row->current_submission_id = $submission->id;
                } elseif ($action === 'submit') {
                    if (! $row->current_submission_id || $row->current_submission_id === $row->reviewed_submission_id) {
                        throw new PrivacyException('NEW_EVIDENCE_REQUIRED', 'Upload new evidence before submission.');
                    }
                    $this->bytes($row->submissions()->findOrFail($row->current_submission_id));
                    $row->status = State::Submitted;
                    $row->submitted_at = now();
                } elseif ($action === 'startReview') {
                    $row->status = State::UnderReview;
                    $row->reviewed_submission_id = $row->current_submission_id;
                } elseif ($action === 'approve') {
                    $this->approveScope($row, $scope, $actor, $data);
                    $row->status = State::Approved;
                    $row->feedback = null;
                } elseif ($action === 'cancel') {
                    $row->status = State::Cancelled;
                } else {
                    $row->status = $action === 'reject' ? State::Rejected : State::NeedsInformation;
                    $row->feedback = $data['reason'];
                }
                if ($row->status->terminal()) {
                    $row->open_scope_id = null;
                    $row->decided_at = now();
                }
                $row->version++;
                $history = $row->history;
                $history[] = ['from' => $from->value, 'to' => $row->status->value, 'action' => $action, 'reason' => $data['reason'] ?? null, 'at' => now()->toISOString()];
                $row->history = $history;
                $row->save();
                $this->audit->write($actor, 'expert_renewal.'.$action, 'expert_scope_renewal', $row->id, $from->value, $row->status->value, strtoupper(Str::snake($action)), ['fromVersion' => $version, 'toVersion' => $row->version]);

                return $row;
            });
        } catch (\Throwable $error) {
            if ($stored) {
                Storage::disk($stored[0])->delete($stored[1]);
            }
            throw $error;
        }
    }

    public function bytes(ExpertScopeRenewalSubmission $submission): string
    {
        $disk = Storage::disk($submission->disk);
        if (! $disk->exists($submission->path)) {
            throw new PrivacyException('EVIDENCE_UNAVAILABLE', 'The evidence file is unavailable.');
        }
        $bytes = $disk->get($submission->path);
        if (! is_string($bytes) || ! hash_equals($submission->checksum, hash('sha256', $bytes))) {
            throw new PrivacyException('EVIDENCE_UNAVAILABLE', 'The evidence file could not be verified.');
        }

        return $bytes;
    }

    private function regulated(ExpertVerifiedScope $scope): bool
    {
        return in_array($scope->domain, CatalogTaxonomy::domains(true), true) || ($scope->evidence_type === 'credential' && $scope->status_checked === 'active');
    }

    private function approveScope(ExpertScopeRenewal $row, ExpertVerifiedScope $scope, Admin $admin, array $data): void
    {
        if (! ($data['evidenceReviewed'] ?? false)) {
            throw ValidationException::withMessages(['evidenceReviewed' => ['A document review attestation is required.']]);
        }
        $submission = $row->submissions()->findOrFail($row->current_submission_id);
        $this->bytes($submission);
        $evidence = $submission->evidence;
        $until = Carbon::parse($data['validUntil']);
        $next = Carbon::parse($data['nextReviewAt']);
        $oldDue = collect([$scope->valid_until, $scope->next_review_at])->filter()->sort()->first();
        if ($until->gt($next) || $until->lte(today()) || ($oldDue && $until->lte($oldDue))) {
            throw ValidationException::withMessages(['validUntil' => ['Validity must advance the previous deadline and cannot outlast the next review.']]);
        }
        $review = $data['professionalReview'] ?? [];
        if (! $this->regulated($scope) && $review !== []) {
            throw ValidationException::withMessages(['professionalReview' => ['Regulatory review is not applicable to this domain.']]);
        }
        $review['nextReviewAt'] = $data['nextReviewAt'];
        // Append evidence only after approval validation, inside this transaction. Never edit the original evidence.
        $application = $scope->kycApplication()->firstOrFail();
        $record = match ($evidence['type']) {
            'credential' => $application->credentials()->create(['type' => $evidence['credentialType'], 'name' => $evidence['name'], 'issuer' => $evidence['issuer'], 'issue_date' => $evidence['issueDate'] ?? null, 'expiry_date' => $evidence['expiryDate'] ?? null]),
            'qualification' => $application->qualifications()->create(['degree' => $evidence['degree'], 'institution' => $evidence['institution'], 'field' => $evidence['field'] ?? null, 'graduation_year' => $evidence['graduationYear'] ?? null]),
            'experience' => $application->experiences()->create(['job_title' => $evidence['jobTitle'], 'organization' => $evidence['organization'], 'description' => $evidence['description'], 'is_current' => true]),
        };
        $document = $application->documents()->create(['document_type' => $evidence['type'] === 'experience' ? 'work_sample' : $evidence['type'],
            'credential_id' => $evidence['type'] === 'credential' ? $record->id : null,
            'qualification_id' => $evidence['type'] === 'qualification' ? $record->id : null,
            'disk' => $submission->disk, 'path' => $submission->path, 'original_name' => 'renewal-evidence.'.pathinfo($submission->path, PATHINFO_EXTENSION),
            'extension' => pathinfo($submission->path, PATHINFO_EXTENSION), 'mime_type' => $submission->mime_type, 'size' => $submission->size, 'checksum' => $submission->checksum]);
        $document->forceFill(['reviewed_at' => now(), 'reviewed_by_admin_id' => $admin->id])->save();
        $application->load(['credentials.document', 'qualifications.document', 'experiences']);
        $application->setRelation('documents', new Collection([$document]));
        $validated = app(ExpertKycWorkflow::class)->verifiedScopeEvidence($application, [[
            'catalogPolicyVersionId' => ExpertCatalogGrant::where('scope_id', $scope->id)->orderByDesc('id')->value('catalog_version_id'),
            'domain' => $scope->domain, 'jurisdiction' => $scope->jurisdiction, 'jurisdictionCountry' => $scope->verified_country,
            'validUntil' => $data['validUntil'], 'evidence' => ['type' => $evidence['type'], 'id' => $record->id], 'professionalReview' => $review,
        ]])[0];
        $successor = $scope->replicate();
        $successor->forceFill(['status' => ExpertScopeStatus::Active, 'verified_by_admin_id' => $admin->id,
            'valid_from' => today(), 'valid_until' => $validated['validUntil'], 'next_review_at' => $next, 'checked_at' => now(),
            'evidence_type' => $evidence['type'], 'evidence_id' => $record->id, 'evidence_document_id' => $document->id,
            'regulator' => $review['regulator'] ?? null, 'registration_number' => $review['registrationNumber'] ?? null,
            'verification_source' => $review['verificationSource'] ?? null, 'status_checked' => $review['statusChecked'] ?? null])->save();
        $scope->forceFill(['status' => ExpertScopeStatus::Revoked])->save();
        $row->replacement_scope_id = $successor->id;
        $row->review = $data + ['adminId' => $admin->id, 'checkedAt' => now()->toISOString(), 'submissionId' => $submission->id];
    }
}
