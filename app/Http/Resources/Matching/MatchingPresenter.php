<?php

namespace App\Http\Resources\Matching;

use App\Models\CaseAccessGrant;
use App\Models\CaseAiQuestionSet;
use App\Models\CaseBrief;
use App\Models\CaseInvitation;
use App\Models\CaseRecord;
use App\Models\ExpertOffering;
use App\Models\MatchingRun;
use App\Services\Reviews\ExpertReviews;

/**
 * Serializers for the Sprint 3 contract. Public/private separation is explicit: Users never see excluded Experts,
 * Experts never see more than the minimum invitation summary until they accept, and nothing exposes storage paths.
 */
final class MatchingPresenter
{
    public static function aiSet(CaseAiQuestionSet $set, CaseRecord $case, bool $reused = false): array
    {
        $answers = $case->currentIntake?->payload['followUpAnswers'] ?? [];

        return ['id' => $set->id, 'caseId' => $set->case_id, 'caseVersion' => $set->case_version, 'status' => $set->status, 'aiAssisted' => true, 'provider' => $set->provider, 'purpose' => $set->purpose,
            'reused' => $reused, 'stale' => $set->intake_version_id !== $case->current_intake_version_id, 'failureCode' => $set->failure_code, 'generatedAt' => $set->completed_at?->toISOString(),
            'disclaimer' => 'AI-assisted suggestions. They are optional and do not affect eligibility or readiness.',
            'questions' => $set->questions->map(fn ($q) => ['id' => $q->id, 'key' => $q->key, 'question' => $q->question, 'reason' => $q->reason, 'priority' => $q->priority, 'status' => $q->status, 'aiAssisted' => true,
                'answer' => $q->status === 'answered' && ($answers[$q->key]['suggestionId'] ?? null) === $q->id ? $answers[$q->key]['answer'] : null])->values()->all()];
    }

    public static function offering(ExpertOffering $o, array $eligibility): array
    {
        $o->loadMissing('entry.versions');
        $policy = $o->entry->versions->sortByDesc('version')->first()->policy ?? [];

        return ['id' => $o->id, 'catalogEntryId' => $o->catalog_entry_id, 'entryCode' => $o->entry->code,
            'service' => ['domain' => $policy['domain'] ?? null, 'specialty' => $policy['specialty'] ?? null, 'serviceType' => $policy['serviceType'] ?? null],
            'deliveryModes' => $o->delivery_modes, 'languages' => $o->languages, 'jurisdictionCodes' => $o->jurisdiction_codes, 'summary' => $o->summary, 'status' => $o->status, 'version' => $o->version,
            'publishedAt' => $o->published_at?->toISOString(), 'eligibility' => $eligibility];
    }

    /** @param list<string> $stale */
    public static function run(MatchingRun $run, array $stale, bool $withCandidates = true): array
    {
        $excluded = $run->candidates()->where('eligible', false)->pluck('exclusion_reasons');
        $counts = [];
        foreach ($excluded as $codes) {
            foreach ($codes as $code) {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }
        ksort($counts);
        $item = ['id' => $run->id, 'caseId' => $run->case_id, 'scopeId' => $run->scope_id, 'caseVersion' => $run->case_version, 'catalogEntryId' => $run->inputs['catalogEntryId'] ?? null,
            'catalogVersion' => $run->inputs['catalogVersion'] ?? null, 'algorithmVersion' => $run->algorithm_version, 'createdAt' => $run->created_at->toISOString(), 'stale' => $stale !== [], 'staleReasons' => $stale,
            'summary' => ['eligibleCount' => $run->eligible_count, 'excludedCount' => $run->excluded_count, 'noMatchReasonCodes' => (object) $counts],
            'nextActions' => $run->eligible_count > 0 ? [] : self::nextActions($counts)];
        if ($withCandidates) {
            $offerings = ExpertOffering::where('catalog_entry_id', $run->inputs['catalogEntryId'] ?? 0)->where('status', ExpertOffering::PUBLISHED)->get()->keyBy('expert_id');
            $candidates = $run->candidates()->where('eligible', true)->with('expert.profile')->orderBy('jurisdiction_code')->orderBy('rank')->get();
            // Informational decision support only (DR-081): never read by eligibility or ranking, never stored in the run snapshot.
            $reputation = app(ExpertReviews::class)->summaries($candidates->pluck('expert_id')->unique()->values()->all());
            $item['candidates'] = $candidates->map(function ($c) use ($offerings, $reputation) {
                $e = $c->expert;
                $o = $offerings->get($e->id);

                return ['candidateId' => $c->id, 'rank' => $c->rank, 'jurisdictionCode' => $c->jurisdiction_code, 'matchReasons' => $c->match_reasons,
                    'expert' => ['slug' => $e->profile?->slug, 'name' => $e->name, 'professionalTitle' => $e->profile?->professional_title, 'yearsExperience' => $e->profile?->years_experience, 'languages' => $e->profile->public_languages ?? [], 'country' => $e->country,
                        'reputation' => ['averageRating' => $reputation[$e->id]['averageRating'], 'reviewCount' => $reputation[$e->id]['reviewCount']]],
                    'offering' => $o ? ['summary' => $o->summary, 'deliveryModes' => $o->delivery_modes, 'languages' => $o->languages] : null];
            })->values()->all();
        }

        return $item;
    }

    /** @param array<string, int> $counts */
    private static function nextActions(array $counts): array
    {
        $actions = ['retry_later'];
        if (isset($counts['DELIVERY_MODE_NOT_SUPPORTED']) || isset($counts['SERVICE_NOT_OFFERED'])) {
            $actions[] = 'change_delivery_mode';
        }
        $actions[] = 'contact_support';

        return $actions;
    }

    public static function brief(CaseBrief $b, bool $withPayload = true): array
    {
        $item = ['id' => $b->id, 'caseId' => $b->case_id, 'scopeId' => $b->scope_id, 'briefVersion' => $b->brief_version, 'caseVersion' => $b->case_version, 'contentHash' => $b->content_hash,
            'contextSnapshotIds' => $b->context_snapshot_ids, 'documentVersionIds' => $b->document_version_ids, 'approvedAt' => $b->approved_at->toISOString()];
        if ($withPayload) {
            $item['content'] = self::content($b->payload);
        }

        return $item;
    }

    /** Stable JSON shape: empty maps stay objects. */
    public static function content(array $payload): array
    {
        $payload['scopeAnswers'] = (object) ($payload['scopeAnswers'] ?? []);

        return $payload;
    }

    public static function invitationForUser(CaseInvitation $i): array
    {
        $i->loadMissing('expert.profile', 'brief');

        return ['id' => $i->id, 'caseId' => $i->case_id, 'scopeId' => $i->scope_id, 'matchingRunId' => $i->matching_run_id, 'candidateId' => $i->matching_candidate_id, 'briefId' => $i->brief_id,
            'briefVersion' => $i->brief->brief_version, 'jurisdictionCode' => $i->jurisdiction_code, 'status' => $i->status, 'expiryReason' => $i->expiry_reason, 'version' => $i->version,
            'expiresAt' => $i->expires_at->toISOString(), 'respondedAt' => $i->responded_at?->toISOString(), 'acceptedAt' => $i->accepted_at?->toISOString(), 'proposalDueAt' => $i->proposal_due_at?->toISOString(),
            'declineReasonCode' => $i->decline_reason_code,
            'expert' => ['slug' => $i->expert->profile?->slug, 'name' => $i->expert->name, 'professionalTitle' => $i->expert->profile?->professional_title], 'createdAt' => $i->created_at->toISOString()];
    }

    /** Minimum summary before acceptance. The Case Brief is added only for an accepted invitation. */
    public static function invitationForExpert(CaseInvitation $i, bool $detail = false, ?array $eligibility = null): array
    {
        $i->loadMissing('scope.catalogVersion', 'caseRecord.currentIntake', 'brief');
        $p = $i->scope->catalogVersion->policy;
        $intake = $i->caseRecord->currentIntake->payload ?? [];
        $item = ['id' => $i->id, 'status' => $i->status, 'expiryReason' => $i->expiry_reason, 'version' => $i->version, 'expiresAt' => $i->expires_at->toISOString(), 'acceptedAt' => $i->accepted_at?->toISOString(),
            'proposalDueAt' => $i->proposal_due_at?->toISOString(), 'createdAt' => $i->created_at->toISOString(),
            'summary' => ['service' => ['domain' => $p['domain'], 'specialty' => $p['specialty'], 'serviceType' => $p['serviceType']], 'deliveryMode' => $i->scope->delivery_mode,
                'jurisdictionCodes' => $i->scope->jurisdiction_codes, 'language' => $intake['language'] ?? null, 'urgency' => $intake['urgency'] ?? null]];
        if ($detail) {
            $item['eligibility'] = $eligibility;
            $item['brief'] = $i->status === CaseInvitation::ACCEPTED ? self::brief($i->brief) : null;
        }

        return $item;
    }

    public static function grant(CaseAccessGrant $g, bool $forExpert = false): array
    {
        $g->loadMissing('documentVersion');
        $v = $g->documentVersion;
        $item = ['id' => $g->id, 'invitationId' => $g->invitation_id, 'documentId' => $g->document_id, 'documentVersionId' => $g->document_version_id, 'status' => $g->status, 'grantedAt' => $g->granted_at->toISOString(),
            'expiresAt' => $g->expires_at->toISOString(), 'revokedAt' => $g->revoked_at?->toISOString()];
        if ($forExpert) {
            $item['document'] = ['category' => $v->category, 'title' => $v->title, 'mimeType' => $v->mime_type, 'size' => $v->size, 'version' => $v->version];
            $item['downloadable'] = $g->isUsable() && (bool) config('matching.document_downloads_enabled');
        }

        return $item;
    }
}
