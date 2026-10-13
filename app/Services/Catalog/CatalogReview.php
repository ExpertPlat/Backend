<?php

namespace App\Services\Catalog;

use App\Enums\CaseStatus;
use App\Enums\ExpertKycStatus;
use App\Models\CaseRecord;
use App\Models\ExpertCatalogGrant;
use App\Models\User;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Facades\DB;

class CatalogReview
{
    public function run(): int
    {
        $count = 0;
        CaseRecord::where('status', '!=', 'cancelled')->where(function ($q) {
            $q->whereHas('serviceScopes', fn ($q) => $q->whereNotNull('submitted_at'))->orWhere('status', 'ready_for_matching');
        })->orderBy('id')->chunkById(100, function ($cases) use (&$count) {
            foreach ($cases as $candidate) {
                $count += DB::transaction(function () use ($candidate) {
                    CatalogMutex::lock();
                    User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
                    $c = CaseRecord::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    if ($c->status === CaseStatus::Cancelled) {
                        return 0;
                    }
                    $scopes = $c->serviceScopes()->whereNotNull('submitted_at')->with('catalogVersion.entry')->get();
                    $changed = false;
                    foreach ($scopes as $s) {
                        $v = $s->catalogVersion;
                        $unsafe = $v->status === 'blocked' || $v->entry->status === 'blocked';
                        foreach ($s->jurisdiction_codes ?: ['GLOBAL'] as $j) {
                            $grants = ExpertCatalogGrant::where('catalog_version_id', $v->id)->where('jurisdiction_code', $j)->whereNull('revoked_at')->with('scope.expert')->get();
                            $evidence = app(ExpertCoverage::class)->evidenceFor($grants->pluck('scope')->filter(), $j);
                            $valid = $grants->filter(fn ($g) => $g->scope && $g->evidence_type === $g->scope->evidence_type && $g->evidence_id === $g->scope->evidence_id && $g->scope->expert->is_active && $g->scope->expert->kyc_status === ExpertKycStatus::Approved && app(ExpertCoverage::class)->scopeValid($g->scope, $v->policy, $j, $evidence))->pluck('scope.expert_id')->unique()->count();
                            if ($valid < $v->policy['minimumExperts']) {
                                $unsafe = true;
                            }
                        }
                        if ($unsafe && $s->status !== 'requires_review') {
                            $s->forceFill(['status' => 'requires_review', 'reason_codes' => ['PROFESSIONAL_COVERAGE_REVIEW_REQUIRED']])->save();
                            $changed = true;
                        }
                    }
                    if (($changed || ($scopes->isEmpty() && $c->status === CaseStatus::ReadyForMatching)) && $c->readiness_status !== 'requires_review') {
                        $c->forceFill(['readiness_status' => 'requires_review', 'readiness_confirmation' => null, 'version' => $c->version + 1])->save();
                        app(AuditWriter::class)->write(null, 'case.requires_review', 'case', $c->id, reason: $scopes->isEmpty() ? 'LEGACY_CATALOG_REVIEW_REQUIRED' : 'PROFESSIONAL_COVERAGE_REVIEW_REQUIRED');

                        return 1;
                    }

                    return 0;
                });
            }
        });

        return $count;
    }
}
