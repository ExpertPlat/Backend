<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Exceptions\PrivacyException;
use App\Models\CaseAiQuestion;
use App\Models\CaseAiQuestionSet;
use App\Models\CaseRecord;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\OpenAiIntakeQuestionService;
use App\Services\Catalog\CaseV2Workflow;
use App\Services\Privacy\AuditWriter;
use App\Services\Privacy\ConsentManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AI-assisted intake enrichment (DR-070). Suggestions are recommendations only: nothing here decides readiness,
 * eligibility or Case state. Accepted answers enter the normal versioned intake through CaseV2Workflow::update.
 */
class AiIntakeQuestions
{
    public function __construct(private OpenAiIntakeQuestionService $provider, private CaseWorkflow $core, private CaseV2Workflow $v2, private AuditWriter $audit) {}

    /** @return array{set: CaseAiQuestionSet, status: int, replayed: bool, reused: bool} */
    public function generate(User $user, int $caseId, string $idempotencyKey, array $data): array
    {
        $keyHash = hash('sha256', $idempotencyKey);
        $regenerate = (bool) ($data['regenerate'] ?? false);
        $requestHash = hash('sha256', json_encode(['case' => $caseId, 'expectedVersion' => (int) $data['expectedVersion'], 'regenerate' => $regenerate], JSON_THROW_ON_ERROR));

        $prepared = DB::transaction(function () use ($user, $caseId, $keyHash, $requestHash, $data, $regenerate) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            $existing = CaseAiQuestionSet::where(['user_id' => $user->id, 'case_id' => $case->id, 'request_key_hash' => $keyHash])->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new PrivacyException('IDEMPOTENCY_CONFLICT', 'This request key has already been used for different input.');
                }

                return ['set' => $existing, 'replayed' => true, 'case' => $case];
            }
            $this->assertAllowed($user, $case, (int) $data['expectedVersion']);
            $intakeId = (int) $case->current_intake_version_id;
            if (! $regenerate) {
                $reuse = CaseAiQuestionSet::where(['case_id' => $case->id, 'intake_version_id' => $intakeId, 'status' => 'completed'])->latest('id')->first();
                if ($reuse) {
                    return ['set' => $reuse, 'reused' => true, 'case' => $case];
                }
            }
            $window = (int) config('openai.timeout_seconds') * 2 + 5;
            if (CaseAiQuestionSet::where(['case_id' => $case->id, 'status' => 'pending'])->where('created_at', '>', now()->subSeconds($window))->exists()) {
                throw new PrivacyException('AI_REQUEST_IN_PROGRESS', 'A suggestion request for this case is already running.');
            }
            $input = $this->buildInput($case);
            $set = new CaseAiQuestionSet;
            $set->forceFill(['case_id' => $case->id, 'user_id' => $user->id, 'intake_version_id' => $intakeId, 'case_version' => $case->version,
                'purpose' => config('openai.intake.purpose'), 'provider' => 'openai', 'model' => $this->provider->model(), 'status' => 'pending',
                'request_key_hash' => $keyHash, 'request_hash' => $requestHash, 'input_summary' => $input['summary']])->save();

            return ['set' => $set, 'case' => $case, 'input' => $input['payload'], 'answered' => array_keys($input['payload']['followUpAnswers'] ?? [])];
        });

        $set = $prepared['set'];
        if (! empty($prepared['replayed'])) {
            return match ($set->status) {
                'completed' => ['set' => $set->load('questions'), 'status' => 200, 'replayed' => true, 'reused' => false],
                'failed' => throw new PrivacyException($set->failure_code, 'AI suggestions are unavailable. Continue with the standard intake.', 503),
                default => throw new PrivacyException('AI_REQUEST_IN_PROGRESS', 'A suggestion request for this case is already running.'),
            };
        }
        if (! empty($prepared['reused'])) {
            return ['set' => $set->load('questions'), 'status' => 200, 'replayed' => false, 'reused' => true];
        }

        // The provider call happens outside any DB transaction/lock: no Case or user row is held during network I/O.
        try {
            $result = $this->provider->suggest($prepared['input'], $prepared['answered']);
        } catch (\Throwable $e) {
            // Any failure (provider, encoding, unexpected) resolves to a stored failed set and a safe code; the Case is untouched.
            $code = $e instanceof AiProviderException ? $e->errorCode : 'AI_PROVIDER_UNAVAILABLE';
            $set->forceFill(['status' => 'failed', 'failure_code' => $code, 'latency_ms' => $e instanceof AiProviderException ? $e->latencyMs : null, 'completed_at' => now()])->save();
            $this->audit->write($user, 'case.ai_questions_failed', 'case', $caseId, reason: $code, metadata: ['provider' => 'openai', 'model' => $set->model, 'purpose' => $set->purpose]);
            throw new PrivacyException($code, 'AI suggestions are unavailable. Continue with the standard intake.', 503);
        }

        DB::transaction(function () use ($set, $result, $user, $caseId) {
            foreach ($result['questions'] as $q) {
                $row = new CaseAiQuestion;
                $row->forceFill(['set_id' => $set->id, 'key' => $q['key'], 'question' => $q['question'], 'reason' => $q['reason'] ?: null, 'priority' => $q['priority'], 'status' => 'pending'])->save();
            }
            $set->forceFill(['status' => 'completed', 'provider_response_id' => $result['responseId'], 'latency_ms' => $result['latencyMs'], 'completed_at' => now()])->save();
            $this->audit->write($user, 'case.ai_questions_generated', 'case', $caseId, reason: 'AI_ASSISTED_SUGGESTION', metadata: ['provider' => 'openai', 'model' => $set->model, 'purpose' => $set->purpose, 'itemCount' => count($result['questions'])]);
        });

        return ['set' => $set->load('questions'), 'status' => 201, 'replayed' => false, 'reused' => false];
    }

    /** @param array{expectedVersion:int, responses: list<array{questionId:int, action:string, answer?:string}>} $data */
    public function respond(User $user, int $caseId, int $setId, array $data): array
    {
        return DB::transaction(function () use ($user, $caseId, $setId, $data) {
            $case = $this->core->lock($user, $caseId, (int) $data['expectedVersion']);
            $set = CaseAiQuestionSet::where(['case_id' => $case->id, 'user_id' => $user->id])->whereKey($setId)->where('status', 'completed')->firstOrFail();
            $this->assertV2AndUnfrozen($case);
            $questions = $set->questions()->get()->keyBy('id');
            $answers = [];
            $apply = [];
            $seen = [];
            foreach ($data['responses'] as $r) {
                $q = $questions->get($r['questionId']);
                if (! $q || isset($seen[$q->id])) {
                    throw ValidationException::withMessages(['responses' => ['Respond once to each question of this suggestion set.']]);
                }
                $seen[$q->id] = true;
                if ($q->status !== 'pending') {
                    throw new PrivacyException('AI_QUESTION_ALREADY_RESOLVED', 'This question has already been resolved.');
                }
                $apply[] = [$q, $r['action']];
                if ($r['action'] === 'answer') {
                    $answers[$q->key] = ['question' => $q->question, 'answer' => $this->clean($r['answer'] ?? ''), 'source' => 'ai_assisted', 'suggestionId' => $q->id, 'answeredAt' => now()->toISOString()];
                }
            }
            $intakeId = null;
            if ($answers) {
                $case = $this->v2->update($user, $caseId, ['expectedVersion' => (int) $data['expectedVersion'], 'followUpAnswers' => $answers]);
                $intakeId = $case->current_intake_version_id;
            }
            foreach ($apply as [$q, $action]) {
                $q->forceFill(['status' => ['answer' => 'answered', 'skip' => 'skipped', 'reject' => 'rejected'][$action], 'resolved_at' => now(),
                    'answered_intake_version_id' => $action === 'answer' ? $intakeId : null])->save();
            }
            $this->audit->write($user, 'case.ai_questions_resolved', 'case', $case->id, reason: 'USER_REVIEWED_AI_SUGGESTIONS', metadata: ['provider' => 'openai', 'itemCount' => count($apply)]);

            return ['set' => $set->load('questions'), 'caseVersion' => (int) $case->fresh()->version];
        });
    }

    private function assertAllowed(User $user, CaseRecord $case, int $expectedVersion): void
    {
        if ($case->version !== $expectedVersion) {
            throw new PrivacyException('VERSION_CONFLICT', 'The case has changed. Refresh before retrying.');
        }
        if (! $case->status->editable()) {
            throw new PrivacyException('INVALID_CASE_TRANSITION', 'This case cannot be edited in its current state.');
        }
        $this->assertV2AndUnfrozen($case);
        $intake = $case->currentIntake->payload ?? [];
        $risk = $intake['answers'] ?? [];
        if (($risk['immediateDanger'] ?? false) || ($risk['requiresInPerson'] ?? false) || ($risk['ambiguousHighRisk'] ?? false)) {
            throw new PrivacyException('AI_INTAKE_NOT_ALLOWED', 'AI suggestions are not used for cases flagged for safety or in-person handling.');
        }
        if (! UserPreference::where('user_id', $user->id)->value('ai_assistance_enabled')) {
            throw new PrivacyException('AI_ASSISTANCE_DISABLED', 'Enable AI assistance in your preferences to receive AI-assisted suggestions.');
        }
        $consent = collect(app(ConsentManager::class)->summaries($user))->firstWhere('purpose', 'ai_assistance');
        if (! ($consent['effective'] ?? false)) {
            throw new PrivacyException('AI_CONSENT_REQUIRED', 'AI assistance consent is required for AI-assisted suggestions.');
        }
        if (blank($intake['problemDescription'] ?? null)) {
            throw ValidationException::withMessages(['problemDescription' => ['Describe the problem before requesting suggestions.']]);
        }
        if (! $this->provider->configured()) {
            throw new PrivacyException('AI_INTAKE_NOT_CONFIGURED', 'AI suggestions are not available. Continue with the standard intake.', 503);
        }
    }

    private function assertV2AndUnfrozen(CaseRecord $case): void
    {
        if ($case->status === CaseStatus::Cancelled) {
            throw new PrivacyException('INVALID_CASE_TRANSITION', 'This case cannot be edited in its current state.');
        }
        if ($case->catalog_contract_version !== 2) {
            throw new PrivacyException('CATALOG_UPGRADE_REQUIRED', 'Use Case Core v2 for AI-assisted intake.');
        }
        if ($case->serviceScopes()->whereNotNull('submitted_at')->exists()) {
            throw new PrivacyException('SUBMITTED_INPUT_IMMUTABLE', 'Shared case input is frozen after scope submission.');
        }
    }

    /**
     * The deliberately minimal payload. Never contains: document bytes/titles, identity/profile fields, ids,
     * risk answers or other Cases.
     *
     * @return array{payload: array<string, mixed>, summary: array<string, mixed>}
     */
    private function buildInput(CaseRecord $case): array
    {
        $intake = $case->currentIntake->payload ?? [];
        $scopes = $case->serviceScopes()->whereNull('detached_at')->with('catalogVersion')->orderBy('id')->get()->map(fn ($s) => [
            'domain' => $s->catalogVersion->policy['domain'], 'specialty' => $s->catalogVersion->policy['specialty'], 'serviceType' => $s->catalogVersion->policy['serviceType'],
            'deliveryMode' => $s->delivery_mode, 'jurisdictionCodes' => $s->jurisdiction_codes, 'answers' => $s->answers,
        ])->all();
        $context = $case->snapshots()->whereNull('detached_at')->orderBy('id')->get()->map(fn ($s) => collect($s->payload['facts'] ?? [])->map(fn ($f) => ['key' => $f['key'] ?? null, 'value' => $f['value'] ?? null])->all())->all();
        $documents = $case->documents()->whereNull('deleted_at')->with('currentVersion')->orderBy('id')->get()->map(fn ($d) => [
            'category' => $d->currentVersion?->category, 'mimeType' => $d->currentVersion?->mime_type, 'sizeBucket' => $this->bucket((int) $d->currentVersion?->size), 'scanState' => $d->currentVersion?->scan_status->value,
        ])->all();
        $followUps = collect($intake['followUpAnswers'] ?? [])->map(fn ($a, $k) => ['question' => $a['question'] ?? '', 'answer' => $a['answer'] ?? ''])->all();
        $payload = array_filter([
            'language' => $intake['language'] ?? null, 'urgency' => $intake['urgency'] ?? null, 'caseCountry' => $intake['jurisdiction'] ?? null,
            'title' => $intake['title'] ?? null, 'problemDescription' => $intake['problemDescription'] ?? null, 'desiredOutcome' => $intake['desiredOutcome'] ?? null,
            'scopes' => $scopes, 'authorizedContextFacts' => $context, 'documentMetadata' => $documents, 'followUpAnswers' => $followUps,
        ], fn ($v) => $v !== null && $v !== []);

        return ['payload' => $payload, 'summary' => [
            'fields' => array_keys($payload), 'scopes' => array_map(fn ($s) => ['domain' => $s['domain'], 'specialty' => $s['specialty'], 'serviceType' => $s['serviceType']], $scopes),
            'contextSnapshotCount' => count($context), 'documentMetadataCount' => count($documents), 'followUpAnswerCount' => count($followUps),
        ]];
    }

    private function bucket(int $bytes): string
    {
        return $bytes < 1_048_576 ? 'under_1mb' : ($bytes < 5_242_880 ? '1_to_5mb' : 'over_5mb');
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', strip_tags($value)) ?? '');
    }
}
