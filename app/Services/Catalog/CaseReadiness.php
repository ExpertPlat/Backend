<?php

namespace App\Services\Catalog;

use App\Enums\CaseDocumentScanStatus;
use App\Enums\CaseStatus;
use App\Models\CaseRecord;
use App\Models\CatalogNode;
use App\Models\CatalogVersion;
use App\Services\Cases\CaseWorkflow;
use App\Services\Cases\IntakeSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CaseReadiness
{
    public function __construct(private ExpertCoverage $coverage, private CatalogPolicy $policy) {}

    public function assess(CaseRecord $case, bool $lock = false): array
    {
        $input = $case->currentIntake->payload ?? [];
        $risk = $input['answers'] ?? [];
        $common = [];
        foreach (['title', 'problemDescription', 'desiredOutcome', 'language', 'urgency'] as $field) {
            if (empty($input[$field])) {
                $common[] = 'CASE_INCOMPLETE';
            }
        }
        foreach (['immediateDanger', 'requiresInPerson', 'ambiguousHighRisk'] as $field) {
            if (! array_key_exists($field, $risk)) {
                $common[] = 'CASE_INCOMPLETE';
            }
        }
        $text = mb_strtolower(($input['title'] ?? '').' '.($input['problemDescription'] ?? ''));
        if ($risk['immediateDanger'] ?? false) {
            $common[] = 'SAFETY_OR_EMERGENCY';
        }
        if ($risk['requiresInPerson'] ?? false) {
            $common[] = 'REMOTE_DELIVERY_NOT_ALLOWED';
        }
        if ($risk['ambiguousHighRisk'] ?? false) {
            $common[] = 'HUMAN_TRIAGE_REQUIRED';
        }
        $rules = CatalogNode::where('kind', 'safety_rule')->where('status', 'enabled')->get();
        if ($rules->isEmpty()) {
            $common[] = 'SAFETY_POLICY_REQUIRED';
        }
        foreach ($rules as $rule) {
            foreach ($rule->rules['keywords'] ?? [] as $word) {
                if (str_contains($text, mb_strtolower($word))) {
                    $common[] = $rule->rules['reasonCode'];
                }
            }
        }
        if (($input['subjectType'] ?? 'self') !== 'self') {
            $common[] = 'SELF_CASES_ONLY';
        }
        $consents = app(CaseWorkflow::class)->consentState($case->owner);
        foreach (['terms', 'privacy'] as $purpose) {
            if (! collect($consents)->first(fn ($c) => $c['purpose'] === $purpose && $c['effective'])) {
                $common[] = 'MISSING_REQUIRED_CONSENT';
            }
        }
        $docs = $case->documents()->whereNull('deleted_at')->with('currentVersion')->get();
        if ($docs->contains(fn ($d) => $d->currentVersion?->scan_status !== CaseDocumentScanStatus::Clean)) {
            $common[] = 'MISSING_REQUIRED_DOCUMENTS';
        }
        $snapshots = $case->snapshots()->whereNull('detached_at')->get();
        $contexts = $case->owner->contexts()->whereIn('id', $snapshots->map(fn ($s) => $s->payload['contextId']))->get()->keyBy('id');
        foreach ($snapshots as $s) {
            if (! $contexts->get($s->payload['contextId'])?->canUseInFutureCase()) {
                $common[] = 'CONTEXT_AUTHORIZATION_CHANGED';
            }
        }
        $results = [];
        foreach ($case->serviceScopes()->whereNull('detached_at')->with('catalogVersion.entry')->orderBy('id')->get() as $s) {
            if ($s->submitted_at) {
                $results[] = ['scopeId' => $s->id, 'status' => $s->status, 'reasonCodes' => $s->reason_codes ?? [], 'coverage' => [], 'alreadySubmitted' => true];

                continue;
            }
            $v = $s->catalogVersion;
            $p = $v->policy;
            $reasons = $common;
            $coverage = [];
            if ($v->entry->current_version_id !== $v->id) {
                $reasons[] = 'CATALOG_VERSION_CHANGED';
            }
            if ($v->status !== 'enabled' || $v->entry->status !== 'enabled') {
                $reasons[] = $p['regulated'] ? 'REGULATED_SERVICE_NOT_ENABLED' : 'SERVICE_NOT_LAUNCHED';
            }
            if (now()->lt(CarbonImmutable::parse($p['effectiveFrom'])) || (! empty($p['effectiveUntil']) && now()->gte(CarbonImmutable::parse($p['effectiveUntil'])))) {
                $reasons[] = 'SERVICE_NOT_LAUNCHED';
            }
            if (! $p['commerciallyAvailable'] || ! $p['operationallyAvailable']) {
                $reasons[] = 'SERVICE_NOT_LAUNCHED';
            }
            if (! $p['remoteDeliveryAllowed']) {
                $reasons[] = 'REMOTE_DELIVERY_NOT_ALLOWED';
            }
            foreach (['domain' => $p['domain'], 'specialty' => $p['specialty'], 'service' => $p['serviceType']] as $kind => $code) {
                if (CatalogNode::where('kind', $kind)->where('code', $code)->value('status') !== 'enabled') {
                    $reasons[] = 'UNSUPPORTED_DOMAIN';
                }
            }
            if (CatalogNode::where('kind', 'delivery_mode')->where('code', $s->delivery_mode)->value('status') !== 'enabled') {
                $reasons[] = 'SERVICE_NOT_LAUNCHED';
            }
            foreach ($s->jurisdiction_codes as $j) {
                $node = CatalogNode::where('kind', 'jurisdiction')->where('code', $j)->first();
                if (! $node || $node->status !== 'enabled' || CatalogNode::find($node->parent_id)?->status !== 'enabled') {
                    $reasons[] = 'UNSUPPORTED_JURISDICTION';
                }
            }
            if (! $s->confirmed) {
                $reasons[] = 'CASE_INCOMPLETE';
            }
            try {
                $this->policy->answers($p['intakeSchema'], $s->answers, true);
            } catch (ValidationException) {
                $reasons[] = 'CASE_INCOMPLETE';
            }
            foreach ($p['requiredDocuments'] as $category) {
                if (! $docs->contains(fn ($d) => $d->currentVersion?->category === $category && $d->currentVersion?->scan_status === CaseDocumentScanStatus::Clean)) {
                    $reasons[] = 'MISSING_REQUIRED_DOCUMENTS';
                }
            }
            foreach ($p['matchingRules']['prohibitedKeywords'] as $term) {
                if (str_contains($text, mb_strtolower($term))) {
                    $reasons[] = 'SAFETY_OR_EMERGENCY';
                }
            }
            if ($p['matchingRules']['humanReviewRequired']) {
                $reasons[] = 'HUMAN_TRIAGE_REQUIRED';
            }
            foreach ($s->jurisdiction_codes ?: ['GLOBAL'] as $j) {
                $count = $this->coverage->count($v, $j, $s->delivery_mode, $input['language'] ?? null, $lock);
                $coverage[] = ['jurisdictionCode' => $j, 'eligibleExperts' => $count, 'minimumExperts' => $p['minimumExperts']];
                if ($count < $p['minimumExperts']) {
                    $reasons[] = 'NO_ELIGIBLE_EXPERT_COVERAGE';
                }
            }
            $reasons = array_values(array_unique($reasons));
            $results[] = ['scopeId' => $s->id, 'status' => $this->status($reasons), 'reasonCodes' => $reasons, 'coverage' => $coverage, 'alreadySubmitted' => false];
        }
        $reasons = array_values(array_unique(array_merge($common, ...array_map(fn ($s) => $s['reasonCodes'], $results))));
        if (! $results) {
            $reasons[] = 'CASE_INCOMPLETE';
        }
        $allReady = $results && collect($results)->every(fn ($s) => $s['status'] === 'ready_for_matching');
        $status = $allReady && ! $common ? 'ready_for_matching' : $this->status($reasons ?: ['CASE_INCOMPLETE']);
        if ($case->status === CaseStatus::Cancelled) {
            $status = 'cancelled';
        }

        return ['status' => $status, 'reasonCodes' => array_values(array_unique($reasons)), 'scopes' => $results, 'suggestions' => $this->suggestions($text), 'nextAction' => in_array('SAFETY_OR_EMERGENCY', $reasons) ? 'seek_local_emergency_assistance' : (in_array('HUMAN_TRIAGE_REQUIRED', $reasons) ? (app(IntakeSchema::class)->triageUrl() ? 'contact_human_triage' : 'stop_contact_support') : 'complete_or_confirm_intake'), 'triageUrl' => app(IntakeSchema::class)->triageUrl()];
    }

    private function suggestions(string $text): array
    {
        $result = [];
        foreach (CatalogVersion::whereIn('status', ['enabled', 'pilot', 'intake_only'])->whereHas('entry', fn ($q) => $q->whereColumn('current_version_id', 'catalog_versions.id'))->orderBy('id')->cursor() as $v) {
            foreach ($v->policy['matchingRules']['keywords'] as $word) {
                if (str_contains($text, mb_strtolower($word))) {
                    $p = $v->policy;
                    $result[] = ['catalogEntryId' => $v->entry_id, 'catalogVersion' => $v->version, 'domain' => $p['domain'], 'specialty' => $p['specialty'], 'serviceType' => $p['serviceType'], 'jurisdictionMode' => $p['jurisdictionMode'], 'jurisdictionCodes' => $p['jurisdictionCodes'], 'requiredExperts' => $p['minimumExperts'], 'origin' => 'rules', 'confidence' => null, 'reasonCode' => 'CATALOG_KEYWORD_INDICATOR', 'requiresConfirmation' => true];
                    break;
                }
            }
            if (count($result) >= 10) {
                break;
            }
        }

        return $result;
    }

    public function fingerprint(CaseRecord $c): string
    {
        return hash_hmac('sha256', json_encode(['input' => $c->current_intake_version_id, 'scopes' => $c->serviceScopes()->whereNull('detached_at')->orderBy('id')->get()->map(fn ($s) => [$s->id, $s->catalog_version_id, $s->delivery_mode, $s->jurisdiction_codes, $s->answers, $s->confirmed])->all(), 'prerequisites' => app(CaseWorkflow::class)->fingerprint($c, $c->owner), 'catalogRevision' => DB::table('catalog_locks')->where('id', 1)->value('revision')], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function status(array $r): string
    {
        foreach (['PROFESSIONAL_COVERAGE_REVIEW_REQUIRED' => 'requires_review', 'SAFETY_OR_EMERGENCY' => 'safety_referral_required', 'REMOTE_DELIVERY_NOT_ALLOWED' => 'not_suitable_for_remote_service', 'UNSUPPORTED_DOMAIN' => 'unsupported_domain', 'UNSUPPORTED_JURISDICTION' => 'unsupported_jurisdiction', 'REGULATED_SERVICE_NOT_ENABLED' => 'regulated_service_not_enabled', 'SERVICE_NOT_LAUNCHED' => 'unsupported_domain', 'HUMAN_TRIAGE_REQUIRED' => 'needs_clarification', 'CASE_INCOMPLETE' => 'needs_clarification', 'MISSING_REQUIRED_DOCUMENTS' => 'needs_clarification', 'MISSING_REQUIRED_CONSENT' => 'needs_clarification', 'NO_ELIGIBLE_EXPERT_COVERAGE' => 'waiting_for_expert_supply'] as $reason => $state) {
            if (in_array($reason, $r, true)) {
                return $state;
            }
        }

        return $r ? 'needs_clarification' : 'ready_for_matching';
    }
}
