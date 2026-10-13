<?php

namespace App\Services\Catalog;

use App\Enums\CaseStatus;
use App\Exceptions\PrivacyException;
use App\Models\CaseRecord;
use App\Models\CaseServiceScope;
use App\Models\CatalogVersion;
use App\Models\User;
use App\Services\Cases\CaseWorkflow;
use App\Services\Commerce\Proposals;
use App\Services\Matching\Invitations;
use App\Services\Privacy\AuditWriter;
use Illuminate\Validation\ValidationException;

class CaseV2Workflow
{
    public function __construct(private CaseWorkflow $core, private CaseReadiness $readiness, private CatalogPolicy $policies, private AuditWriter $audit) {}

    public function create(User $u, array $d): CaseRecord
    {
        if (array_key_exists('caseCountry', $d)) {
            $d['jurisdiction'] = $d['caseCountry'];
            unset($d['caseCountry']);
        }
        $c = $this->core->create($u, $d);
        $c->forceFill(['catalog_contract_version' => 2, 'readiness_status' => 'draft'])->save();

        return $c;
    }

    public function update(User $u, int $id, array $d): CaseRecord
    {
        $c = $u->cases()->findOrFail($id);
        if ($c->serviceScopes()->whereNotNull('submitted_at')->exists()) {
            throw new PrivacyException('SUBMITTED_INPUT_IMMUTABLE', 'Shared case input is frozen after scope submission.');
        }
        if (array_key_exists('caseCountry', $d)) {
            $d['jurisdiction'] = $d['caseCountry'];
            unset($d['caseCountry']);
        }
        $c = $this->core->update($u, $id, $d);
        $c->forceFill(['catalog_contract_version' => 2, 'readiness_status' => 'intake_in_progress'])->save();

        return $c;
    }

    public function scopes(User $u, int $id, array $d): CaseRecord
    {
        $c = $this->core->lock($u, $id, $d['expectedVersion']);
        $old = $c->serviceScopes()->whereNull('detached_at')->get();
        $submitted = $old->whereNotNull('submitted_at');
        if ($submitted->count() + count($d['scopes']) > 20) {
            throw ValidationException::withMessages(['scopes' => 'At most 20 retained scopes.']);
        }
        $prepared = [];
        $seen = [];
        foreach ($d['scopes'] as $s) {
            $v = CatalogVersion::where('entry_id', $s['catalogEntryId'])->where('version', $s['catalogVersion'])->whereIn('status', ['enabled', 'pilot', 'intake_only', 'paused'])->firstOrFail();
            $p = $v->policy;
            if (! in_array($s['deliveryMode'], $p['deliveryModes'], true)) {
                throw ValidationException::withMessages(['deliveryMode' => 'Choose a catalog delivery mode.']);
            }
            $given = $s['jurisdictionCodes'];
            $allowed = $p['jurisdictionCodes'];
            sort($given);
            sort($allowed);
            if (count($given) !== count(array_unique($given)) || $given !== $allowed) {
                throw ValidationException::withMessages(['jurisdictionCodes' => 'Select the full exact jurisdiction set for this catalog entry.']);
            }
            $key = $v->entry_id.'|'.$s['deliveryMode'].'|'.implode(',', $given);
            if (isset($seen[$key]) || $submitted->contains(fn ($r) => $r->catalogVersion->entry_id === $v->entry_id)) {
                throw new PrivacyException('DUPLICATE_CASE_SCOPE', 'Duplicate service scope.');
            }$seen[$key] = true;
            $prepared[] = [$v, $s, $this->policies->answers($p['intakeSchema'], $s['answers'])];
        }
        foreach ($old->whereNull('submitted_at') as $s) {
            $s->forceFill(['detached_at' => now()])->save();
        }
        foreach ($prepared as [$v,$s,$answers]) {
            $row = new CaseServiceScope;
            $row->forceFill(['case_id' => $id, 'catalog_version_id' => $v->id, 'delivery_mode' => $s['deliveryMode'], 'jurisdiction_codes' => $s['jurisdictionCodes'], 'answers' => $answers, 'confirmed' => $s['confirmed']])->save();
        }
        $c->forceFill(['catalog_contract_version' => 2, 'readiness_status' => 'intake_in_progress']);
        $this->core->touch($c, $u, 'case.scopes_selected');

        return $this->core->loaded($c);
    }

    public function assess(User $u, int $id, array $d): CaseRecord
    {
        $c = $this->core->lock($u, $id, $d['expectedVersion']);
        $result = $this->readiness->assess($c);
        $this->record($c, $result);
        $c->readiness_status = $result['status'] === 'ready_for_matching' ? 'intake_in_progress' : $result['status'];
        $c->readiness_confirmation = null;
        $this->core->touch($c, $u, 'case.catalog_assessed', false);

        return $c;
    }

    public function confirm(User $u, int $id, array $d): CaseRecord
    {
        $c = $this->core->lock($u, $id, $d['expectedVersion']);
        $result = $this->readiness->assess($c);
        $this->record($c, $result);
        // Partial confirmation is allowed; submit still requires per-scope readiness and explicit partial consent.
        if (! collect($result['scopes'])->contains(fn ($s) => $s['status'] === 'ready_for_matching' && ! $s['alreadySubmitted'])) {
            throw new PrivacyException('CASE_NOT_READY', 'No eligible unsubmitted scope is ready for confirmation.');
        }
        $c->readiness_confirmation = $this->readiness->fingerprint($c);
        $this->core->touch($c, $u, 'case.catalog_confirmed', false);

        return $c;
    }

    public function submit(User $u, int $id, array $d): CaseRecord
    {
        $c = $this->core->lock($u, $id, $d['expectedVersion']);
        if (! $c->readiness_confirmation || ! hash_equals($c->readiness_confirmation, $this->readiness->fingerprint($c))) {
            throw new PrivacyException('INTAKE_ASSESSMENT_STALE', 'Confirm current catalog scopes before submitting.');
        }
        $result = $this->readiness->assess($c, true);
        $pending = collect($result['scopes'])->where('alreadySubmitted', false);
        $ids = $d['selectedScopeIds'] ?? $pending->pluck('scopeId')->all();
        if (! $ids || array_diff($ids, $pending->pluck('scopeId')->all())) {
            throw ValidationException::withMessages(['selectedScopeIds' => 'Select only owned unsubmitted scopes.']);
        }
        if (count($ids) < $pending->count() && ! ($d['partialConsent'] ?? false)) {
            throw new PrivacyException('PARTIAL_CONSENT_REQUIRED', 'Explicit partial submission consent is required.');
        }
        foreach ($pending->whereIn('scopeId', $ids) as $s) {
            if ($s['status'] !== 'ready_for_matching') {
                throw new PrivacyException($s['reasonCodes'][0] ?? 'CASE_NOT_READY', 'A selected scope is not eligible. Assess the case for reason codes.');
            }
        }
        $this->record($c, $result);
        $docs = $c->documents()->whereNull('deleted_at')->get(['id', 'current_version_id']);
        foreach ($c->serviceScopes()->whereIn('id', $ids)->with('catalogVersion')->get() as $s) {
            $v = $s->catalogVersion;
            $s->forceFill(['status' => 'ready_for_matching', 'reason_codes' => [], 'submitted_at' => now(), 'readiness_checked_at' => now(),
                'policy_snapshot' => ['catalogEntryId' => $v->entry_id, 'catalogVersion' => $v->version, 'jurisdictionCodes' => $s->jurisdiction_codes, 'policy' => $v->policy, 'policyHash' => $v->policy_hash, 'intakeVersionId' => $c->current_intake_version_id, 'contextSnapshotIds' => $c->snapshots()->whereNull('detached_at')->orderBy('id')->pluck('id')->all(), 'documentVersions' => $docs->map(fn ($d) => ['documentId' => $d->id, 'versionId' => $d->current_version_id])->all(), 'consents' => $this->core->consentState($u)]])->save();
            $this->audit->write($u, 'case.scope_submitted', 'case', $c->id, reason: count($ids) < $pending->count() ? 'PARTIAL_SCOPE_CONSENT' : 'FULL_SCOPE_CONSENT', metadata: ['itemCount' => count($ids)]);
        }
        $all = $c->serviceScopes()->whereNull('detached_at')->get()->every(fn ($s) => $s->submitted_at && $s->status === 'ready_for_matching');
        $c->readiness_status = $all ? 'ready_for_matching' : $result['status'];
        if (! $all && $c->readiness_status === 'ready_for_matching') {
            $c->readiness_status = 'intake_in_progress';
        }
        $c->status = $all ? CaseStatus::ReadyForMatching : CaseStatus::NeedsInformation;
        $c->submitted_at = $all ? now() : null;
        $c->readiness_checked_at = now();
        $c->readiness_confirmation = null;
        $this->core->touch($c, $u, 'case.catalog_submitted', false);
        if ($all) {
            $this->audit->write($u, 'case.status_changed', 'case', $c->id, null, 'ready_for_matching');
        }

        return $c;
    }

    public function cancel(User $u, int $id, array $d): CaseRecord
    {
        $c = $this->core->cancel($u, $id, $d);
        $c->forceFill(['readiness_status' => 'cancelled', 'readiness_confirmation' => null])->save();
        // Sprint 4 first (it takes Expert locks before invitation locks), then Sprint 3: a cancelled Case ends every open
        // invitation and revokes Expert document access.
        app(Proposals::class)->cancelForCase($c, $u);
        app(Invitations::class)->revokeForCase($c, $u);

        return $c;
    }

    private function record(CaseRecord $c, array $r): void
    {
        foreach ($r['scopes'] as $s) {
            if (! $s['alreadySubmitted']) {
                $c->serviceScopes()->whereKey($s['scopeId'])->update(['status' => $s['status'] === 'ready_for_matching' ? 'intake_in_progress' : $s['status'], 'reason_codes' => json_encode($s['reasonCodes']), 'readiness_checked_at' => now()]);
            }
        }
        $c->readiness_checked_at = now();
    }
}
