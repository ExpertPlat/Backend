<?php

namespace App\Services\Catalog;

use App\Enums\ExpertKycStatus;
use App\Enums\ExpertScopeStatus;
use App\Models\CatalogNode;
use App\Models\CatalogVersion;
use App\Models\Expert;
use App\Models\ExpertCatalogGrant;
use App\Models\ExpertKycCredential;
use App\Models\ExpertKycDocument;
use App\Models\ExpertKycExperience;
use App\Models\ExpertKycQualification;
use App\Models\ExpertVerifiedScope;
use Illuminate\Support\Collection;

class ExpertCoverage
{
    public function scopeValid(ExpertVerifiedScope $s, array $p, string $jurisdiction, ?array $evidence = null): bool
    {
        if ($s->status !== ExpertScopeStatus::Active || $s->valid_from->isFuture() && ! $s->valid_from->isToday()) {
            return false;
        }
        foreach ([$s->valid_until, $s->next_review_at] as $expiry) {
            if ($expiry && $expiry->isPast() && ! $expiry->isToday()) {
                return false;
            }
        }
        if ($s->domain !== $p['domain'] || $s->evidence_type !== $p['requiredEvidenceType'] || ! $s->evidence_id || ! $s->evidence_document_id) {
            return false;
        }
        $evidence ??= $this->evidenceFor(collect([$s]), $jurisdiction);
        $doc = $evidence['documents']->get($s->evidence_document_id);
        if (! $doc || $doc->application_id !== $s->kyc_application_id || ! $doc->reviewed_at) {
            return false;
        }
        $record = ($evidence['records'][$s->evidence_type] ?? collect())->get($s->evidence_id);
        if (! $record || $record->application_id !== $s->kyc_application_id) {
            return false;
        }
        if ($s->evidence_type === 'qualification' && $doc->qualification_id !== $record->id) {
            return false;
        }
        if ($s->evidence_type === 'credential' && $doc->credential_id !== $record->id) {
            return false;
        }
        if ($jurisdiction !== 'GLOBAL') {
            if (! $evidence['country'] || strtoupper((string) $s->verified_country) !== $evidence['country']) {
                return false;
            }
        }
        if ($p['regulated'] || $p['requiresProfessionalLicense']) {
            $license = $s->evidence_type === 'credential' ? $record : null;
            if ($s->evidence_type !== 'credential' || ! $license || $license->application_id !== $s->kyc_application_id || $license->type !== 'license' || $s->status_checked !== 'active' || ! $s->next_review_at) {
                return false;
            }
            if ($license->expiry_date && $license->expiry_date->isPast() && ! $license->expiry_date->isToday()) {
                return false;
            }
        }

        return true;
    }

    /** @param Collection<int, ExpertVerifiedScope> $scopes */
    public function evidenceFor(Collection $scopes, string $jurisdiction): array
    {
        $records = [];
        foreach (['qualification' => ExpertKycQualification::class, 'credential' => ExpertKycCredential::class, 'experience' => ExpertKycExperience::class] as $type => $model) {
            $records[$type] = $model::whereIn('id', $scopes->where('evidence_type', $type)->pluck('evidence_id'))->get()->keyBy('id');
        }
        $node = $jurisdiction === 'GLOBAL' ? null : CatalogNode::where('kind', 'jurisdiction')->where('code', $jurisdiction)->first();

        return ['documents' => ExpertKycDocument::whereIn('id', $scopes->pluck('evidence_document_id'))->get()->keyBy('id'),
            'records' => $records, 'country' => $node ? CatalogNode::whereKey($node->parent_id)->value('code') : null];
    }

    public function count(CatalogVersion $v, string $jurisdiction, ?string $delivery = null, ?string $language = null, bool $lock = false): int
    {
        $grants = ExpertCatalogGrant::where('catalog_version_id', $v->id)->where('jurisdiction_code', $jurisdiction)->whereNull('revoked_at')->with('scope')->get();
        $ids = $grants->pluck('scope.expert_id')->filter()->unique()->sort()->values();
        $query = Expert::whereIn('id', $ids)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $experts = $query->with(['profile', 'availability'])->get()->keyBy('id');
        // Re-read all scope rows once after parent locks, then batch evidence; no per-expert query loop.
        if ($lock) {
            $grants->load('scope');
        }
        $evidence = $this->evidenceFor($grants->pluck('scope')->filter(), $jurisdiction);
        $eligible = [];
        foreach ($grants as $grant) {
            $s = $grant->scope;
            if (! $s) {
                continue;
            }
            $e = $experts->get($s->expert_id);
            $a = $e?->availability;
            if (! $e || ! $e->is_active || ! $e->hasVerifiedEmail() || $e->kyc_status !== ExpertKycStatus::Approved || ! $e->profile?->is_published || ! $a?->accepting_new_requests || $a->max_active_requests < 1) {
                continue;
            }
            if (! $this->scopeValid($s, $v->policy, $jurisdiction, $evidence)) {
                continue;
            }
            if ($grant->evidence_type !== $s->evidence_type || $grant->evidence_id !== $s->evidence_id) {
                continue;
            }
            if (! $delivery && ! array_intersect($v->policy['deliveryModes'], $s->service_types ?? [], $a->service_modes ?? [])) {
                continue;
            }
            if ($delivery && (! in_array($delivery, $s->service_types ?? [], true) || ! in_array($delivery, $a->service_modes ?? [], true))) {
                continue;
            }
            if ($language && ! in_array($language, $s->languages ?? [], true)) {
                continue;
            }
            $eligible[$e->id] = true;
        }

        return count($eligible);
    }
}
