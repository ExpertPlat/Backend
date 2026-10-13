<?php

namespace App\Services\Front;

use App\Exceptions\PrivacyException;
use App\Http\Front\Front;
use App\Models\CaseAnalysis;
use App\Models\CaseRecord;
use App\Models\CaseServiceScope;
use App\Models\User;
use App\Services\Catalog\CaseV2Workflow;
use App\Services\Catalog\CatalogMutex;
use App\Services\Cases\CaseWorkflow;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The v7 intake journey on top of Case Core v2:
 *   create (story) → assessment (AI/rules reading: summary, facts, ranked scopes, questions) → scopes (include/exclude)
 *   → question answers → confirm → submit (per-scope readiness, then matching).
 * Versioning, readiness, consent and catalog rules are still enforced by CaseWorkflow / CaseV2Workflow / CaseReadiness.
 */
class FrontCases
{
    public function __construct(private CaseWorkflow $core, private CaseV2Workflow $v2, private CaseAnalyzer $analyzer, private AuditWriter $audit) {}

    /** FE body → intake fields. */
    private function intake(array $d): array
    {
        $map = ['story' => 'problemDescription', 'title' => 'title', 'language' => 'language', 'residence' => 'residence', 'caseCountry' => 'caseCountry', 'urgency' => 'urgency'];
        $out = [];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $d)) {
                $out[$to] = is_string($d[$from]) ? trim($d[$from]) : $d[$from];
            }
        }
        if (isset($out['problemDescription']) && empty($out['title'])) {
            $out['title'] = $this->autoTitle($out['problemDescription']);
        }

        return $out;
    }

    private function autoTitle(string $story): string
    {
        $story = trim(preg_replace('/\s+/u', ' ', $story) ?? '');

        return mb_strlen($story) > 60 ? rtrim(mb_substr($story, 0, 60)).'…' : $story;
    }

    public function create(User $u, array $d): CaseRecord
    {
        return DB::transaction(function () use ($u, $d) {
            CatalogMutex::lock();
            $data = $this->intake($d) + ['language' => 'ar', 'urgency' => 'normal'];
            $data['caseCountry'] ??= 'AE';

            return $this->v2->create($u, $data);
        });
    }

    public function update(User $u, int $id, array $d): CaseRecord
    {
        return DB::transaction(function () use ($u, $id, $d) {
            CatalogMutex::lock();
            $version = (int) $d['expectedVersion'];
            $fields = $this->intake($d);
            $case = $u->cases()->findOrFail($id);
            if ($fields) {
                $case = $this->v2->update($u, $id, $fields + ['expectedVersion' => $version]);
                $version = $case->version;
            }
            if (array_key_exists('summary', $d)) {
                $case = $this->core->lock($u, $id, $version);
                $analysis = $case->analysis ?? throw new PrivacyException('ANALYSIS_REQUIRED', 'Analyse the case before editing its summary.');
                $analysis->forceFill(['summary' => mb_substr(trim((string) $d['summary']), 0, 2000)])->save();
                $this->core->touch($case, $u, 'case.summary_edited');
            }

            return $case->fresh();
        });
    }

    /** @return array{assessedAt: string, suitability: string, case: CaseRecord} */
    public function assess(User $u, int $id, int $expected): array
    {
        return DB::transaction(function () use ($u, $id, $expected) {
            CatalogMutex::lock();
            $case = $this->core->lock($u, $id, $expected);
            if ($case->serviceScopes()->whereNotNull('submitted_at')->exists()) {
                throw new PrivacyException('SUBMITTED_INPUT_IMMUTABLE', 'This case was already submitted.');
            }
            $result = $this->analyzer->analyze($case->load('currentIntake'), $u);
            $intake = $case->currentIntake->payload ?? [];
            // The reading completes the intake fields the v7 form does not ask for directly.
            $case = $this->core->update($u, $id, ['expectedVersion' => $expected, 'title' => ($intake['title'] ?? '') ?: ($result['title'] ?: $this->autoTitle($intake['problemDescription'] ?? '')),
                'desiredOutcome' => ($intake['desiredOutcome'] ?? '') ?: (Front::t($result['desiredOutcome']) ?: Front::t($result['summary'])), 'answers' => $result['risk']]);
            $case->serviceScopes()->whereNull('detached_at')->whereNull('submitted_at')->update(['detached_at' => now()]);
            $notes = [];
            foreach ($result['scopes'] as $s) {
                $v = $this->analyzer->catalog()->firstWhere('id', $s['versionId']);
                if (! $v) {
                    continue;
                }
                $modes = $v->policy['deliveryModes'];
                $row = new CaseServiceScope;
                $row->forceFill(['case_id' => $case->id, 'catalog_version_id' => $v->id, 'delivery_mode' => in_array('video_consultation', $modes, true) ? 'video_consultation' : $modes[0],
                    'jurisdiction_codes' => $v->policy['jurisdictionCodes'], 'answers' => [], 'confirmed' => $s['confirmed'] ?? true, 'status' => 'intake_in_progress'])->save();
                $notes[$v->id] = ['why' => $s['why'], 'score' => $s['score'], 'priority' => $s['priority'], 'general' => (bool) ($s['general'] ?? false)];
            }
            $analysis = CaseAnalysis::where('case_id', $case->id)->first() ?? new CaseAnalysis;
            $analysis->forceFill(['case_id' => $case->id, 'source' => $result['source'], 'model' => $result['model'], 'summary' => Front::t($result['summary']),
                'facts' => $result['facts'], 'scope_notes' => $notes, 'questions' => $result['questions'], 'answers' => [],
                'question_version' => ($analysis->question_version ?? 0) + 1, 'suitability' => $result['suitability'], 'assessed_at' => now()])->save();
            $this->audit->write($u, 'case.analyzed', 'case', $case->id, reason: $result['source'] === 'ai' ? 'AI_ASSISTED_ANALYSIS' : 'RULES_ANALYSIS',
                metadata: ['itemCount' => count($notes), 'model' => $result['model']]);
            $case = $this->v2->assess($u, $id, ['expectedVersion' => $case->fresh()->version]);

            return ['assessedAt' => Front::iso($analysis->assessed_at), 'suitability' => $result['suitability'], 'case' => $case->fresh()];
        });
    }

    public function scopes(User $u, int $id, array $d): CaseRecord
    {
        return DB::transaction(function () use ($u, $id, $d) {
            CatalogMutex::lock();
            $case = $this->core->lock($u, $id, (int) $d['expectedVersion']);
            $active = $case->serviceScopes()->whereNull('detached_at')->whereNull('submitted_at')->with('catalogVersion')->get();
            $wanted = [];
            foreach ($d['scopes'] as $s) {
                $wanted[(int) ($s['catalogEntryId'] ?? 0)] = (bool) ($s['confirmed'] ?? $s['included'] ?? false);
            }
            foreach ($active as $row) {
                if (array_key_exists($row->catalogVersion->entry_id, $wanted)) {
                    $row->forceFill(['confirmed' => $wanted[$row->catalogVersion->entry_id]])->save();
                }
            }
            if (! $case->serviceScopes()->whereNull('detached_at')->where('confirmed', true)->exists()) {
                throw ValidationException::withMessages(['scopes' => [Front::t(['ar' => 'اختر تخصصًا واحدًا على الأقل.', 'en' => 'Choose at least one specialty.'])]]);
            }
            $this->core->touch($case, $u, 'case.scopes_selected');

            return $case->fresh();
        });
    }

    public function questionSet(User $u, int $id, int $expected, bool $regenerate): CaseRecord
    {
        return DB::transaction(function () use ($u, $id, $expected, $regenerate) {
            $case = $u->cases()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($case->version !== $expected) {
                throw new PrivacyException('VERSION_CONFLICT', 'The case has changed. Refresh before retrying.');
            }
            $analysis = $case->analysis ?? throw new PrivacyException('ANALYSIS_REQUIRED', 'Analyse the case before loading its questions.');
            if ($regenerate) {
                $result = $this->analyzer->analyze($case->load('currentIntake'), $u);
                $analysis->forceFill(['questions' => $result['questions'], 'answers' => [], 'question_version' => $analysis->question_version + 1])->save();
            }

            return $case->fresh();
        });
    }

    public function answer(User $u, int $id, int $setId, array $d): CaseRecord
    {
        return DB::transaction(function () use ($u, $id, $setId, $d) {
            $case = $u->cases()->whereKey($id)->lockForUpdate()->firstOrFail();
            $analysis = CaseAnalysis::where('case_id', $case->id)->whereKey($setId)->lockForUpdate()->firstOrFail();
            if ($case->serviceScopes()->whereNotNull('submitted_at')->exists()) {
                throw new PrivacyException('SUBMITTED_INPUT_IMMUTABLE', 'This case was already submitted.');
            }
            if ((int) $d['expectedVersion'] !== $analysis->question_version) {
                throw new PrivacyException('VERSION_CONFLICT', 'The questions changed elsewhere. Reload and try again.');
            }
            $questions = collect($analysis->questions ?? [])->keyBy('id');
            $answers = $analysis->answers ?? [];
            foreach ($d['responses'] as $i => $r) {
                $q = $questions->get((string) $r['questionId']);
                if (! $q) {
                    throw ValidationException::withMessages(["responses.$i.questionId" => ['Unknown question.']]);
                }
                if (($r['action'] ?? 'answer') !== 'answer') {
                    unset($answers[$q['id']]);

                    continue;
                }
                $answer = $r['answer'] ?? null;
                if (! is_int($answer) || $answer < 0 || $answer >= count($q['options'])) {
                    throw ValidationException::withMessages(["responses.$i.answer" => ['Pick one of the options.']]);
                }
                $answers[$q['id']] = $answer;
            }
            $analysis->forceFill(['answers' => $answers, 'question_version' => $analysis->question_version + 1])->save();
            if ($case->readiness_confirmation) {
                $case->forceFill(['readiness_confirmation' => null, 'confirmed_at' => null])->save();
            }

            return $case->fresh();
        });
    }

    public function confirm(User $u, int $id, int $expected): CaseRecord
    {
        return DB::transaction(function () use ($u, $id, $expected) {
            CatalogMutex::lock();
            $case = $this->core->lock($u, $id, $expected);
            $analysis = $case->analysis ?? throw new PrivacyException('ANALYSIS_REQUIRED', 'Analyse the case before confirming it.');
            $questions = $analysis->questions ?? [];
            $answers = $analysis->answers ?? [];
            foreach ($questions as $q) {
                if (! array_key_exists($q['id'], $answers)) {
                    throw ValidationException::withMessages(['answers' => [Front::t(['ar' => 'أجب عن كل الأسئلة أولًا.', 'en' => 'Answer all the questions first.'])]]);
                }
            }
            $case->serviceScopes()->whereNull('detached_at')->whereNull('submitted_at')->where('confirmed', false)->update(['detached_at' => now()]);
            $lang = ($case->currentIntake->payload['language'] ?? 'ar') === 'en' ? 'en' : 'ar';
            $text = fn ($v) => is_array($v) && array_key_exists('ar', $v) ? ($v[$lang] ?? $v['ar']) : (string) $v;
            $follow = [];
            foreach ($questions as $q) {
                $follow[$q['id']] = ['question' => $text($q['label']), 'answer' => $text($q['options'][$answers[$q['id']]] ?? ''), 'source' => $analysis->source === 'ai' ? 'ai_assisted' : 'user', 'answeredAt' => now()->toISOString()];
            }
            $case = $this->v2->update($u, $id, ['expectedVersion' => $expected, 'followUpAnswers' => $follow]);
            try {
                $case = $this->v2->confirm($u, $id, ['expectedVersion' => $case->version]);
            } catch (PrivacyException $e) {
                if ($e->errorCode !== 'CASE_NOT_READY') {
                    throw $e;
                }
                $status = $case->fresh()->readiness_status;
                throw new PrivacyException('CASE_NOT_READY', Front::t(match ($status) {
                    'waiting_for_expert_supply' => ['ar' => 'لا يتوفر حاليًا خبراء معتمدون لهذه التخصصات. سنبقي حالتك محفوظة.', 'en' => 'No verified experts are available for these specialties right now. Your case is saved.'],
                    'safety_referral_required' => ['ar' => 'تحتاج حالتك إلى جهة مختصة مباشرة ولا يمكن معالجتها عبر المنصة.', 'en' => 'Your case needs a specialist service directly and cannot be handled on the platform.'],
                    default => ['ar' => 'الحالة غير جاهزة بعد. راجع التخصصات والبيانات ثم حاول مرة أخرى.', 'en' => 'The case is not ready yet. Review the specialties and details, then try again.'],
                }), 409);
            }
            $case->forceFill(['confirmed_at' => now()])->save();

            return $case->fresh();
        });
    }

    public function submit(User $u, int $id, array $d): CaseRecord
    {
        return DB::transaction(function () use ($u, $id, $d) {
            CatalogMutex::lock();
            $case = $u->cases()->findOrFail($id);
            $pending = $case->serviceScopes()->whereNull('detached_at')->whereNull('submitted_at')->pluck('id')->all();
            $ids = array_values(array_intersect(array_map('intval', $d['selectedScopeIds'] ?? $pending), $pending));
            if (! $ids) {
                throw ValidationException::withMessages(['selectedScopeIds' => ['Select at least one scope.']]);
            }
            $partial = count($ids) < count($pending) ? true : (bool) ($d['partialConsent'] ?? false);

            return $this->v2->submit($u, $id, ['expectedVersion' => (int) $d['expectedVersion'], 'selectedScopeIds' => $ids, 'partialConsent' => $partial])->fresh();
        });
    }

    public function cancel(User $u, int $id, int $expected): CaseRecord
    {
        return DB::transaction(fn () => $this->v2->cancel($u, $id, ['expectedVersion' => $expected])->fresh());
    }
}
