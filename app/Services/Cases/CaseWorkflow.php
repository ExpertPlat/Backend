<?php

namespace App\Services\Cases;

use App\Enums\CaseDocumentScanStatus;
use App\Enums\CaseStatus;
use App\Enums\CaseSuitability;
use App\Exceptions\PrivacyException;
use App\Models\CaseContextSnapshot;
use App\Models\CaseDomain;
use App\Models\CaseIntakeAssessment;
use App\Models\CaseIntakeVersion;
use App\Models\CaseRecord;
use App\Models\User;
use App\Services\Privacy\AuditWriter;
use App\Services\Privacy\ConsentManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CaseWorkflow
{
    public function __construct(private readonly AuditWriter $audit, private readonly DeterministicIntake $classifier, private readonly ConsentManager $consents) {}

    public function loaded(CaseRecord $case): CaseRecord
    {
        return $case->load(['currentIntake', 'domains', 'snapshots' => fn ($q) => $q->whereNull('detached_at'), 'latestAssessment']);
    }

    public function lock(User $user, int $id, int $expected, bool $editable = true): CaseRecord
    {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        $case = $user->cases()->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($case->version !== $expected) {
            throw new PrivacyException('VERSION_CONFLICT', 'The case has changed. Refresh before retrying.');
        }
        if ($editable && ! $case->status->editable()) {
            throw new PrivacyException('INVALID_CASE_TRANSITION', 'This case cannot be edited in its current state.');
        }

        return $case;
    }

    public function create(User $user, array $data): CaseRecord
    {
        return DB::transaction(function () use ($user, $data) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $case = new CaseRecord;
            $case->forceFill(['user_id' => $user->id, 'status' => CaseStatus::Draft, 'version' => 1, 'suitability' => CaseSuitability::NotAssessed])->save();
            $this->saveInput($case, array_replace(['schemaVersion' => 1, 'privacyChoice' => 'private', 'subjectType' => 'self', 'answers' => []], $data));
            $this->audit->write($user, 'case.created', 'case', $case->id, null, $case->status->value);

            return $this->loaded($case);
        });
    }

    public function update(User $user, int $id, array $data): CaseRecord
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $case = $this->lock($user, $id, (int) $data['expectedVersion']);
            if ($case->serviceScopes()->whereNotNull('submitted_at')->exists()) {
                throw new PrivacyException('SUBMITTED_INPUT_IMMUTABLE', 'Submitted case input is immutable.');
            }
            $old = $case->currentIntake->payload;
            unset($data['expectedVersion']);
            if (isset($data['answers'])) {
                $data['answers'] = array_replace($old['answers'] ?? [], $data['answers']);
            }
            if (isset($data['followUpAnswers'])) {
                $data['followUpAnswers'] = array_replace($old['followUpAnswers'] ?? [], $data['followUpAnswers']);
            }
            $this->saveInput($case, array_replace($old, $data));
            $this->touch($case, $user, 'case.intake_updated');

            return $this->loaded($case);
        });
    }

    private function saveInput(CaseRecord $case, array $data): void
    {
        $data = array_intersect_key($data, array_flip(IntakeSchema::FIELDS));
        if (isset($data['answers'])) {
            $data['answers'] = array_map(fn ($value) => (bool) $value, $data['answers']);
        }
        $version = new CaseIntakeVersion;
        $version->forceFill(['case_id' => $case->id, 'version' => ($case->intakeVersions()->max('version') ?? 0) + 1, 'schema_version' => 1, 'payload' => $data, 'created_at' => now()])->save();
        $case->forceFill(['current_intake_version_id' => $version->id, 'primary_domain' => $data['primaryDomain'] ?? null, 'jurisdiction' => $data['jurisdiction'] ?? null, 'language' => $data['language'] ?? null, 'urgency' => $data['urgency'] ?? null, 'title_fingerprint' => isset($data['title']) ? $this->titleHash($data['title']) : null])->save();
        $case->domains()->delete();
        foreach ($data['domains'] ?? [] as $domain) {
            $row = new CaseDomain;
            $row->forceFill(['case_id' => $case->id, 'domain' => $domain])->save();
        }
        $case->unsetRelation('currentIntake');
    }

    public function titleHash(string $title): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($title)), (string) config('app.key'));
    }

    public function touch(CaseRecord $case, ?User $user, string $action, bool $invalidate = true, ?string $reason = null): void
    {
        $from = $case->version;
        $case->version++;
        if ($invalidate) {
            $case->readiness_confirmation = null;
            $case->confirmed_assessment_id = null;
            $case->confirmed_at = null;
            if ($case->catalog_contract_version === 2 && ! $case->submitted_at) {
                $case->readiness_status = 'intake_in_progress';
            }
            $case->suitability = CaseSuitability::NotAssessed;
        }
        $case->save();
        $this->audit->write($user, $action, 'case', $case->id, reason: $reason, metadata: ['fromVersion' => $from, 'toVersion' => $case->version]);
    }

    private function transition(CaseRecord $case, User $user, CaseStatus $target): void
    {
        $from = $case->status;
        if (! $from->permits($target)) {
            throw new PrivacyException('INVALID_CASE_TRANSITION', 'The requested case transition is not allowed.');
        }
        $case->status = $target;
        if ($target === CaseStatus::ReadyForMatching) {
            $case->submitted_at = now();
        }
        if ($target === CaseStatus::Cancelled) {
            $case->cancelled_at = now();
            $case->readiness_status = 'cancelled';
            $case->serviceScopes()->whereNull('detached_at')->update(['status' => 'cancelled']);
            $case->readiness_confirmation = null;
        }
        $this->audit->write($user, 'case.status_changed', 'case', $case->id, $from->value, $target->value);
    }

    public function cancel(User $user, int $id, array $data): CaseRecord
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $case = $this->lock($user, $id, (int) $data['expectedVersion'], false);
            $this->transition($case, $user, CaseStatus::Cancelled);
            $this->touch($case, $user, 'case.cancelled', false);

            return $this->loaded($case);
        });
    }

    public function consentState(User $user): array
    {
        return array_values(array_filter($this->consents->summaries($user), fn ($v) => in_array($v['purpose'], ['terms', 'privacy'], true)));
    }

    public function attach(User $user, int $id, array $data): CaseRecord
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $case = $this->lock($user, $id, (int) $data['expectedVersion']);
            $context = $user->contexts()->whereKey($data['contextId'])->lockForUpdate()->firstOrFail();
            if (! $context->canUseInFutureCase() || $context->current_version !== (int) $data['contextVersion']) {
                throw new PrivacyException('CONTEXT_NOT_REUSABLE', 'Refresh the context and confirm an eligible version.');
            }
            if ($case->snapshots()->whereNull('detached_at')->count() >= 20) {
                throw ValidationException::withMessages(['contextId' => ['A case can use at most 20 active context snapshots.']]);
            }
            if ($case->snapshots()->whereNull('detached_at')->where('source_context_version_id', $context->latestVersion->id)->exists()) {
                throw new PrivacyException('CONTEXT_ALREADY_ATTACHED', 'This context version is already attached.');
            }
            $privacy = collect($this->consentState($user))->firstWhere('purpose', 'privacy');
            if (! ($privacy['effective'] ?? false)) {
                throw new PrivacyException('POLICY_CONFIGURATION_REQUIRED', 'Accept the current Privacy policy before authorizing context use.');
            }
            $facts = $context->latestVersion->payload['facts'] ?? [];
            if (array_diff($data['selectedFactKeys'], array_column($facts, 'key'))) {
                throw ValidationException::withMessages(['selectedFactKeys' => ['Select only fields present in this context version.']]);
            }
            $snapshot = new CaseContextSnapshot;
            $snapshot->forceFill(['case_id' => $case->id, 'source_context_version_id' => $context->latestVersion->id, 'policy_version_id' => $privacy['policyVersionId'],
                'selected_fact_keys' => $data['selectedFactKeys'], 'payload' => ['contextId' => $context->id, 'contextVersion' => $context->current_version, 'domain' => $context->domain, 'country' => $context->country, 'facts' => array_values(array_filter($facts, fn ($f) => in_array($f['key'], $data['selectedFactKeys'], true)))], 'authorized_at' => now()])->save();
            $this->touch($case, $user, 'case.context_attached');

            return $this->loaded($case);
        });
    }

    public function detach(User $user, int $id, int $snapshot, array $data): CaseRecord
    {
        return DB::transaction(function () use ($user, $id, $snapshot, $data) {
            $case = $this->lock($user, $id, (int) $data['expectedVersion']);
            $row = $case->snapshots()->whereNull('detached_at')->findOrFail($snapshot);
            $row->detached_at = now();
            $row->save();
            $this->touch($case, $user, 'case.context_detached');

            return $this->loaded($case);
        });
    }

    public function fingerprint(CaseRecord $case, User $user): string
    {
        $payload = ['inputVersion' => $case->current_intake_version_id,
            'snapshots' => $case->snapshots()->whereNull('detached_at')->orderBy('id')->get(['id', 'source_context_version_id'])->toArray(),
            'documents' => $case->documents()->whereNull('deleted_at')->with('currentVersion')->orderBy('id')->get()->map(fn ($d) => [$d->id, $d->current_version_id, $d->currentVersion?->scan_status->value])->all(),
            'policies' => $this->consentState($user), 'rules' => config('case_intake.rules_version'),
            'catalogRevision' => DB::table('catalog_locks')->where('id', 1)->value('revision')];

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function evaluate(CaseRecord $case, User $user): array
    {
        $result = $this->classifier->assess($case->currentIntake->payload);
        $blocking = [];
        foreach ($this->consentState($user) as $consent) {
            if (! $consent['effective']) {
                $blocking[] = ['field' => 'consents.'.$consent['purpose'], 'reasonCode' => 'CURRENT_POLICY_CONSENT_REQUIRED'];
            }
        }
        $documents = $case->documents()->whereNull('deleted_at')->with('currentVersion')->get();
        if ($documents->contains(fn ($d) => $d->currentVersion?->scan_status !== CaseDocumentScanStatus::Clean)) {
            $blocking[] = ['field' => 'documents', 'reasonCode' => 'DOCUMENT_SCAN_REQUIRED'];
        }
        $snapshots = $case->snapshots()->whereNull('detached_at')->get();
        $ids = $snapshots->map(fn ($s) => $s->payload['contextId'])->all();
        $contexts = $user->contexts()->whereIn('id', $ids)->with('latestVersion')->get()->keyBy('id');
        foreach ($snapshots as $snapshot) {
            $context = $contexts->get($snapshot->payload['contextId']);
            // Explicit snapshots do not drift when source is edited; withdrawal/archive blocks future submit.
            if (! $context || ! $context->canUseInFutureCase()) {
                $blocking[] = ['field' => 'contextSnapshots', 'reasonCode' => 'CONTEXT_AUTHORIZATION_CHANGED'];
                break;
            }
        }
        if ($blocking) {
            $result['clarifications'] = array_merge($result['clarifications'], $blocking);
            $result['reasonCodes'][] = 'PREREQUISITES_INCOMPLETE';
            if ($result['suitability'] === CaseSuitability::Suitable->value) {
                $result['suitability'] = CaseSuitability::NeedsClarification->value;
                $result['nextAction'] = 'complete_information';
            }
        }
        $result['relatedCases'] = $case->title_fingerprint ? $user->cases()->where('title_fingerprint', $case->title_fingerprint)->whereKeyNot($case->id)->where('status', '!=', 'cancelled')->orderByDesc('id')->limit(5)->pluck('id')->all() : [];

        return $result;
    }

    public function assess(User $user, int $id, array $data): CaseRecord
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $case = $this->lock($user, $id, (int) $data['expectedVersion']);
            $result = $this->evaluate($case, $user);
            $row = new CaseIntakeAssessment;
            $row->forceFill(['case_id' => $case->id, 'intake_version_id' => $case->current_intake_version_id, 'input_fingerprint' => $this->fingerprint($case, $user), 'rules_version' => config('case_intake.rules_version'), 'suitability' => $result['suitability'], 'result' => $result, 'created_at' => now()])->save();
            $case->readiness_confirmation = null;
            $case->confirmed_assessment_id = null;
            $case->confirmed_at = null;
            if ($case->catalog_contract_version === 2 && ! $case->submitted_at) {
                $case->readiness_status = 'intake_in_progress';
            }
            $case->suitability = CaseSuitability::from($result['suitability']);
            if ($case->suitability !== CaseSuitability::Suitable && $case->status !== CaseStatus::NeedsInformation) {
                $this->transition($case, $user, CaseStatus::NeedsInformation);
            }
            $this->touch($case, $user, 'case.assessed', false);

            return $this->loaded($case);
        });
    }

    private function currentAssessment(CaseRecord $case, User $user, ?int $id = null): CaseIntakeAssessment
    {
        $row = $case->assessments()->latest('id')->first();
        if (! $row || ($id !== null && $row->id !== $id) || ! hash_equals($row->input_fingerprint, $this->fingerprint($case, $user))) {
            throw new PrivacyException('INTAKE_ASSESSMENT_STALE', 'Evaluate the current case input before confirmation or submission.');
        }

        return $row;
    }

    public function confirm(User $user, int $id, array $data): CaseRecord
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $case = $this->lock($user, $id, (int) $data['expectedVersion']);
            $row = $this->currentAssessment($case, $user, (int) $data['assessmentId']);
            if ($row->suitability !== CaseSuitability::Suitable->value || $this->evaluate($case, $user)['suitability'] !== CaseSuitability::Suitable->value) {
                throw new PrivacyException('CASE_NOT_READY', 'Complete the permitted intake requirements before confirming.');
            }
            $case->confirmed_assessment_id = $row->id;
            $case->confirmed_at = now();
            $this->touch($case, $user, 'case.intake_confirmed', false);

            return $this->loaded($case);
        });
    }

    public function submit(User $user, int $id, array $data): CaseRecord
    {
        $case = $user->cases()->findOrFail($id);
        if ($case->catalog_contract_version === 2) {
            throw new PrivacyException('CATALOG_UPGRADE_REQUIRED', 'Use Case Core v2 for catalog submission.');
        }
        $result = $this->evaluate($case, $user);
        if ($result['clarifications']) {
            throw ValidationException::withMessages(collect($result['clarifications'])->mapWithKeys(fn ($q) => [$q['field'] => [$q['reasonCode']]])->all());
        }
        throw new PrivacyException('CATALOG_UPGRADE_REQUIRED', 'Select and confirm catalog scopes using Case Core v2 before submission.');
    }
}
