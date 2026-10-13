<?php

namespace App\Services\Matching;

use App\Enums\CaseDocumentScanStatus;
use App\Exceptions\PrivacyException;
use App\Models\CaseBrief;
use App\Models\CaseRecord;
use App\Models\CaseServiceScope;
use App\Models\User;
use App\Services\Cases\CaseWorkflow;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Immutable, user-approved, scope-specific Case Brief. Only explicitly selected optional data is shared and
 * nothing outside the selection (risk answers, profile, other documents/contexts) is ever included.
 */
class CaseBriefs
{
    public function __construct(private MatchEligibility $eligibility, private CaseWorkflow $core, private AuditWriter $audit) {}

    /** @return array{payload: array<string, mixed>, hash: string, caseVersion: int, contextSnapshotIds: list<int>, documentVersionIds: list<int>} */
    public function preview(User $user, int $caseId, int $scopeId, array $d): array
    {
        $case = $user->cases()->whereKey($caseId)->firstOrFail();
        if ($case->version !== (int) $d['expectedVersion']) {
            throw new PrivacyException('VERSION_CONFLICT', 'The case has changed. Refresh before retrying.');
        }

        return $this->build($case, $case->serviceScopes()->with('catalogVersion')->findOrFail($scopeId), $d);
    }

    public function approve(User $user, int $caseId, int $scopeId, array $d): CaseBrief
    {
        return DB::transaction(function () use ($user, $caseId, $scopeId, $d) {
            $case = $this->core->lock($user, $caseId, (int) $d['expectedVersion'], false);
            $scope = $case->serviceScopes()->with('catalogVersion')->findOrFail($scopeId);
            $built = $this->build($case, $scope, $d);
            if (! hash_equals($built['hash'], (string) $d['contentHash'])) {
                throw new PrivacyException('BRIEF_PREVIEW_STALE', 'The shared content changed since the preview. Review the new preview before approving.');
            }
            $brief = new CaseBrief;
            $brief->forceFill(['case_id' => $case->id, 'scope_id' => $scope->id, 'brief_version' => (CaseBrief::where('scope_id', $scope->id)->max('brief_version') ?? 0) + 1,
                'case_version' => $case->version, 'intake_version_id' => $case->current_intake_version_id, 'catalog_version_id' => $scope->catalog_version_id,
                'content_hash' => $built['hash'], 'payload' => $built['payload'], 'context_snapshot_ids' => $built['contextSnapshotIds'], 'document_version_ids' => $built['documentVersionIds'],
                'approved_at' => now(), 'created_at' => now()])->save();
            $this->audit->write($user, 'case.brief_approved', 'case', $case->id, reason: 'USER_APPROVED_BRIEF', metadata: ['briefId' => $brief->id, 'itemCount' => count($built['documentVersionIds']) + count($built['contextSnapshotIds'])]);

            return $brief;
        });
    }

    private function build(CaseRecord $case, CaseServiceScope $scope, array $d): array
    {
        $blocks = $this->eligibility->scopeBlocks($case, $scope);
        if ($blocks) {
            throw new PrivacyException($blocks[0], 'This service scope cannot currently be shared.');
        }
        $intake = $case->currentIntake->payload ?? [];
        $p = $scope->catalogVersion->policy;

        $snapshotIds = collect($d['contextSnapshotIds'])->map('intval')->unique()->sort()->values();
        $snapshots = $case->snapshots()->whereNull('detached_at')->whereIn('id', $snapshotIds)->orderBy('id')->get();
        if ($snapshots->count() !== $snapshotIds->count()) {
            throw ValidationException::withMessages(['contextSnapshotIds' => ['Select only context snapshots attached to this case.']]);
        }
        $followKeys = collect($d['followUpKeys'])->unique()->sort()->values();
        $answers = $intake['followUpAnswers'] ?? [];
        if ($followKeys->contains(fn ($k) => ! isset($answers[$k]))) {
            throw ValidationException::withMessages(['followUpKeys' => ['Select only answered follow-up questions of this case.']]);
        }
        $documentIds = collect($d['documentIds'])->map('intval')->unique()->sort()->values();
        $documents = $case->documents()->whereNull('deleted_at')->whereIn('id', $documentIds)->with('currentVersion')->orderBy('id')->get();
        if ($documents->count() !== $documentIds->count()) {
            throw ValidationException::withMessages(['documentIds' => ['Select only documents attached to this case.']]);
        }
        foreach ($documents as $doc) {
            if ($doc->currentVersion?->scan_status !== CaseDocumentScanStatus::Clean) {
                throw new PrivacyException('DOCUMENT_NOT_READY', 'Only documents that passed the security scan can be included.');
            }
        }
        $payload = [
            'title' => $intake['title'] ?? null, 'problemDescription' => $intake['problemDescription'] ?? null, 'desiredOutcome' => $intake['desiredOutcome'] ?? null,
            // v7: the reading the User reviewed and approved on the analysis screen (summary + key facts).
            'summary' => $case->analysis?->summary, 'facts' => $case->analysis?->facts ?? [],
            'language' => $intake['language'] ?? null, 'urgency' => $intake['urgency'] ?? null, 'caseCountry' => $intake['jurisdiction'] ?? null,
            'service' => ['domain' => $p['domain'], 'specialty' => $p['specialty'], 'serviceType' => $p['serviceType'], 'deliveryMode' => $scope->delivery_mode, 'jurisdictionCodes' => $scope->jurisdiction_codes],
            'scopeAnswers' => (object) ($scope->answers ?? []),
            'followUps' => $followKeys->map(fn ($k) => ['key' => $k, 'question' => $answers[$k]['question'] ?? '', 'answer' => $answers[$k]['answer'] ?? '', 'source' => $answers[$k]['source'] ?? 'user'])->all(),
            'context' => $snapshots->map(fn ($s) => ['snapshotId' => $s->id, 'domain' => $s->payload['domain'] ?? null, 'country' => $s->payload['country'] ?? null, 'facts' => $s->payload['facts'] ?? []])->all(),
            'documents' => $documents->map(fn ($doc) => ['documentId' => $doc->id, 'versionId' => $doc->currentVersion->id, 'version' => $doc->currentVersion->version, 'category' => $doc->currentVersion->category,
                'title' => $doc->currentVersion->title, 'mimeType' => $doc->currentVersion->mime_type, 'size' => $doc->currentVersion->size])->all(),
        ];
        $hash = hash('sha256', json_encode(['payload' => $payload, 'caseVersion' => $case->version, 'scopeId' => $scope->id, 'intakeVersionId' => $case->current_intake_version_id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return ['payload' => $payload, 'hash' => $hash, 'caseVersion' => $case->version, 'contextSnapshotIds' => $snapshots->pluck('id')->all(), 'documentVersionIds' => $documents->map(fn ($doc) => $doc->currentVersion->id)->all()];
    }
}
