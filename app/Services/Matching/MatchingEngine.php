<?php

namespace App\Services\Matching;

use App\Exceptions\PrivacyException;
use App\Models\CaseRecord;
use App\Models\CaseServiceScope;
use App\Models\MatchingRun;
use App\Models\User;
use App\Services\Cases\CaseWorkflow;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Facades\DB;

/** Deterministic, explainable matching. Creates an append-only run snapshot; it never assigns or invites anyone. */
class MatchingEngine
{
    public function __construct(private MatchEligibility $eligibility, private CaseWorkflow $core, private AuditWriter $audit) {}

    public function create(User $user, int $caseId, int $scopeId, int $expectedVersion): MatchingRun
    {
        $key = config('matching.tie_break_key');
        if (! is_string($key) || $key === '') {
            throw new PrivacyException('MATCHING_NOT_CONFIGURED', 'Matching is not available right now.', 503);
        }

        return DB::transaction(function () use ($user, $caseId, $scopeId, $expectedVersion, $key) {
            $case = $this->core->lock($user, $caseId, $expectedVersion, false);
            $scope = $case->serviceScopes()->with('catalogVersion.entry')->findOrFail($scopeId);
            $blocks = $this->eligibility->scopeBlocks($case, $scope);
            if ($blocks) {
                throw new PrivacyException($blocks[0], $blocks[0] === 'CASE_NOT_READY' ? 'This service scope is not ready for matching.' : 'The service is not currently available for matching.');
            }
            $v = $scope->catalogVersion;
            $language = $case->currentIntake->payload['language'] ?? null;
            $universe = $this->eligibility->universe($v);
            $seed = bin2hex(random_bytes(16));
            $rows = [];
            $eligible = 0;
            foreach ($scope->jurisdiction_codes ?: ['GLOBAL'] as $jurisdiction) {
                $results = $this->eligibility->evaluate($v, $jurisdiction, $scope->delivery_mode, $language, $universe);
                $ranked = [];
                foreach ($results as $expertId => $r) {
                    $hash = $r['eligible'] ? hash_hmac('sha256', $seed.':'.$expertId, $key) : null;
                    $rows[$jurisdiction.':'.$expertId] = ['expert_id' => $expertId, 'jurisdiction_code' => $jurisdiction, 'eligible' => $r['eligible'], 'rank' => null, 'tie_break_hash' => $hash,
                        'match_reasons' => json_encode($r['matchReasons']), 'exclusion_reasons' => json_encode($r['exclusionReasons']), 'created_at' => now()];
                    if ($r['eligible']) {
                        $ranked[] = [$hash, $expertId];
                    }
                }
                sort($ranked);
                foreach ($ranked as $i => [, $expertId]) {
                    $rows[$jurisdiction.':'.$expertId]['rank'] = $i + 1;
                    $eligible++;
                }
            }
            $run = new MatchingRun;
            $run->forceFill(['case_id' => $case->id, 'scope_id' => $scope->id, 'case_version' => $case->version, 'intake_version_id' => $case->current_intake_version_id,
                'catalog_version_id' => $v->id, 'catalog_revision' => (int) DB::table('catalog_locks')->where('id', 1)->value('revision'), 'algorithm_version' => config('matching.algorithm_version'),
                'tie_break_seed' => $seed, 'inputs' => ['domain' => $v->policy['domain'], 'specialty' => $v->policy['specialty'], 'serviceType' => $v->policy['serviceType'], 'catalogEntryId' => $v->entry_id, 'catalogVersion' => $v->version,
                    'deliveryMode' => $scope->delivery_mode, 'jurisdictionCodes' => $scope->jurisdiction_codes, 'language' => $language],
                'eligible_count' => $eligible, 'excluded_count' => count($rows) - $eligible, 'created_at' => now()])->save();
            foreach (array_chunk(array_values($rows), 200) as $chunk) {
                DB::table('matching_run_candidates')->insert(array_map(fn ($r) => $r + ['run_id' => $run->id], $chunk));
            }
            $this->audit->write($user, 'case.matching_run_created', 'case', $case->id, reason: $eligible ? 'MATCHES_FOUND' : 'NO_MATCH', metadata: ['matchingRunId' => $run->id, 'eligibleCount' => $eligible, 'candidateCount' => count($rows)]);

            return $run;
        });
    }

    /** A run is a snapshot, never authorization. @return list<string> */
    public function staleReasons(MatchingRun $run, CaseRecord $case, CaseServiceScope $scope): array
    {
        $reasons = [];
        if ($run->case_version !== $case->version) {
            $reasons[] = 'CASE_VERSION_CHANGED';
        }
        if ($this->eligibility->scopeBlocks($case, $scope) !== []) {
            $reasons[] = 'SCOPE_NOT_READY';
        }

        return $reasons;
    }
}
