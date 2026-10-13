<?php

namespace App\Services\Matching;

use App\Enums\CaseStatus;
use App\Enums\ExpertKycStatus;
use App\Enums\ExpertScopeStatus;
use App\Models\Agreement;
use App\Models\CaseInvitation;
use App\Models\CaseRecord;
use App\Models\CaseServiceScope;
use App\Models\CatalogNode;
use App\Models\CatalogVersion;
use App\Models\Expert;
use App\Models\ExpertCatalogGrant;
use App\Models\ExpertOffering;
use App\Models\ExpertVerifiedScope;
use App\Models\Proposal;
use App\Services\Catalog\ExpertCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single, explainable source of eligibility truth for matching, invitation send and invitation accept.
 * Rules only: no AI, ratings, popularity or paid priority.
 */
class MatchEligibility
{
    public function __construct(private ExpertCoverage $coverage) {}

    /**
     * Request-level gates. CASE_NOT_READY: the scope cannot enter matching. POLICY_BLOCKED: the pinned catalog policy
     * is not currently allowed to be matched.
     *
     * @return list<string>
     */
    public function scopeBlocks(CaseRecord $case, CaseServiceScope $scope): array
    {
        $blocks = [];
        if ($case->status === CaseStatus::Cancelled || $scope->detached_at || ! $scope->submitted_at || $scope->status !== 'ready_for_matching' || $case->readiness_status === 'requires_review') {
            $blocks[] = 'CASE_NOT_READY';
        }
        $v = $scope->catalogVersion()->with('entry')->firstOrFail();
        $p = $v->policy;
        $blocked = $v->status !== 'enabled' || $v->entry->status !== 'enabled' || ! $p['commerciallyAvailable'] || ! $p['operationallyAvailable'] || ! $p['remoteDeliveryAllowed']
            || now()->lt(CarbonImmutable::parse($p['effectiveFrom'])) || (! empty($p['effectiveUntil']) && now()->gte(CarbonImmutable::parse($p['effectiveUntil'])))
            || CatalogNode::where('kind', 'delivery_mode')->where('code', $scope->delivery_mode)->value('status') !== 'enabled';
        foreach ($scope->jurisdiction_codes as $j) {
            $node = CatalogNode::where('kind', 'jurisdiction')->where('code', $j)->first();
            $blocked = $blocked || ! $node || $node->status !== 'enabled' || CatalogNode::find($node->parent_id)?->status !== 'enabled';
        }
        if ($blocked) {
            $blocks[] = 'POLICY_BLOCKED';
        }

        return $blocks;
    }

    /**
     * Experts that could possibly be evaluated for this catalog version: those holding a grant, plus those with a
     * published offering for the entry (so that "no verified scope" is explainable).
     *
     * @return list<int>
     */
    public function universe(CatalogVersion $v): array
    {
        $viaGrants = ExpertCatalogGrant::where('catalog_version_id', $v->id)->whereNull('revoked_at')->with('scope:id,expert_id')->get()->pluck('scope.expert_id');
        $viaOffering = ExpertOffering::where('catalog_entry_id', $v->entry_id)->where('status', ExpertOffering::PUBLISHED)->pluck('expert_id');

        return $viaGrants->merge($viaOffering)->filter()->unique()->sort()->values()->take((int) config('matching.max_universe'))->all();
    }

    /**
     * @param  list<int>  $expertIds
     * @return array<int, array{eligible: bool, matchReasons: list<string>, exclusionReasons: list<string>}>
     */
    public function evaluate(CatalogVersion $v, string $jurisdiction, string $delivery, ?string $language, array $expertIds, bool $lock = false, ?int $excludeInvitationId = null): array
    {
        if ($expertIds === []) {
            return [];
        }
        $query = Expert::whereIn('id', $expertIds)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $experts = $query->with(['profile', 'availability'])->get()->keyBy('id');
        $grants = ExpertCatalogGrant::where('catalog_version_id', $v->id)->whereNull('revoked_at')->with('scope')->get()
            ->filter(fn ($g) => $g->scope && in_array($g->scope->expert_id, $expertIds, true))->groupBy(fn ($g) => $g->scope->expert_id);
        $forJurisdiction = $grants->flatten(1)->filter(fn ($g) => $g->jurisdiction_code === $jurisdiction);
        $evidence = $this->coverage->evidenceFor($forJurisdiction->pluck('scope')->filter(), $jurisdiction);
        $offerings = ExpertOffering::where('catalog_entry_id', $v->entry_id)->whereIn('expert_id', $expertIds)->get()->keyBy('expert_id');
        $active = $this->engagedCounts($expertIds, $excludeInvitationId);

        $results = [];
        foreach ($expertIds as $id) {
            $e = $experts->get($id);
            $results[$id] = $e
                ? $this->one($e, $v, $jurisdiction, $delivery, $language, $grants->get($id, new Collection), $evidence, $offerings->get($id), (int) ($active[$id] ?? 0), $excludeInvitationId !== null)
                : ['eligible' => false, 'matchReasons' => [], 'exclusionReasons' => ['EXPERT_RESTRICTED']];
        }

        return $results;
    }

    /** Single-expert live revalidation (invitation send/accept). Pass $lock inside a transaction before capacity decisions. */
    public function revalidate(Expert $expert, CatalogVersion $v, string $jurisdiction, string $delivery, ?string $language, bool $lock = false, ?int $excludeInvitationId = null): array
    {
        return $this->evaluate($v, $jurisdiction, $delivery, $language, [$expert->id], $lock, $excludeInvitationId)[$expert->id];
    }

    /**
     * Accepted invitations that currently hold an Expert's capacity: the proposal window is open and no proposal exists,
     * or a live proposal exists, or a non-cancelled Agreement exists (Sprint 4).
     *
     * @param  list<int>  $expertIds
     * @return Collection<int, int>
     */
    private function engagedCounts(array $expertIds, ?int $excludeInvitationId): Collection
    {
        $exists = fn (string $table, ?array $statuses, bool $in = true) => function ($q) use ($table, $statuses, $in): void {
            $q->select(DB::raw(1))->from($table)->whereColumn($table.'.invitation_id', 'case_invitations.id');
            if ($statuses !== null) {
                $in ? $q->whereIn($table.'.status', $statuses) : $q->whereNotIn($table.'.status', $statuses);
            }
        };

        return CaseInvitation::whereIn('expert_id', $expertIds)->where('status', CaseInvitation::ACCEPTED)
            ->when($excludeInvitationId, fn ($q) => $q->where('id', '!=', $excludeInvitationId))
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('proposal_due_at', '>', now())->whereNotExists($exists('proposals', null)))
                ->orWhereExists($exists('proposals', Proposal::LIVE))
                ->orWhereExists($exists('agreements', [Agreement::CANCELLED, Agreement::FUNDING_EXPIRED], false)))
            ->selectRaw('expert_id, count(*) as total')->groupBy('expert_id')->pluck('total', 'expert_id');
    }

    private function one(Expert $e, CatalogVersion $v, string $jurisdiction, string $delivery, ?string $language, Collection $grants, array $evidence, ?ExpertOffering $offering, int $active, bool $engaged = false): array
    {
        $ex = [];
        if (! $e->is_active) {
            $ex[] = 'EXPERT_RESTRICTED';
        }
        if (! $e->hasVerifiedEmail() || $e->kyc_status !== ExpertKycStatus::Approved) {
            $ex[] = 'EXPERT_NOT_VERIFIED';
        }
        $grant = $grants->first(fn ($g) => $g->jurisdiction_code === $jurisdiction);
        if (! $grant) {
            $ex[] = $grants->isNotEmpty() ? 'JURISDICTION_NOT_SUPPORTED' : 'NO_VERIFIED_SCOPE';
        } else {
            $s = $grant->scope;
            if ($this->dateExpired($s)) {
                $ex[] = 'VERIFIED_SCOPE_EXPIRED';
            } elseif (! $this->coverage->scopeValid($s, $v->policy, $jurisdiction, $evidence) || $grant->evidence_type !== $s->evidence_type || $grant->evidence_id !== $s->evidence_id) {
                $ex[] = 'NO_VERIFIED_SCOPE';
            } else {
                if (! in_array($delivery, $s->service_types ?? [], true)) {
                    $ex[] = 'DELIVERY_MODE_NOT_SUPPORTED';
                }
                if ($language && ! in_array($language, $s->languages ?? [], true)) {
                    $ex[] = 'LANGUAGE_MISMATCH';
                }
            }
        }
        if (! $offering || $offering->status !== ExpertOffering::PUBLISHED || ! in_array($delivery, $offering->delivery_modes, true)) {
            $ex[] = 'SERVICE_NOT_OFFERED';
        } else {
            if ($offering->jurisdiction_codes !== null && ! in_array($jurisdiction, $offering->jurisdiction_codes, true)) {
                $ex[] = 'JURISDICTION_NOT_SUPPORTED';
            }
            if ($language && ! in_array($language, $offering->languages, true)) {
                $ex[] = 'LANGUAGE_MISMATCH';
            }
        }
        if (! $e->profile?->is_published) {
            $ex[] = 'PROFILE_NOT_PUBLISHED';
        }
        $a = $e->availability;
        // An Expert with an existing engagement may pause *new* requests without breaking work already agreed.
        if (! $a || (! $engaged && ! $a->accepting_new_requests) || $a->max_active_requests < 1) {
            $ex[] = 'NO_AVAILABILITY';
        } elseif (! in_array($delivery, $a->service_modes ?? [], true)) {
            $ex[] = 'DELIVERY_MODE_NOT_SUPPORTED';
        } elseif ($active >= $a->max_active_requests) {
            $ex[] = 'CAPACITY_REACHED';
        }
        $ex = array_values(array_unique($ex));
        if ($ex) {
            return ['eligible' => false, 'matchReasons' => [], 'exclusionReasons' => $ex];
        }

        return ['eligible' => true, 'exclusionReasons' => [], 'matchReasons' => ['DOMAIN_MATCH', 'JURISDICTION_MATCH', 'ACTIVE_VERIFIED_SCOPE', 'SERVICE_OFFERED', 'DELIVERY_MODE_MATCH', 'LANGUAGE_MATCH', 'PROFILE_PUBLISHED', 'AVAILABILITY_OPEN', 'CAPACITY_AVAILABLE', 'REMOTE_DELIVERY_ALLOWED']];
    }

    private function dateExpired(ExpertVerifiedScope $s): bool
    {
        if ($s->status !== ExpertScopeStatus::Active) {
            return false;
        }
        foreach ([$s->valid_until, $s->next_review_at] as $date) {
            if ($date && $date->isPast() && ! $date->isToday()) {
                return true;
            }
        }

        return false;
    }
}
