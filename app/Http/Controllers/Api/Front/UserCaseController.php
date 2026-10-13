<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Http\Front\FrontResponses;
use App\Http\Front\Presenters\CasePresenter;
use App\Models\User;
use App\Services\Front\FrontCases;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/v2/user/cases — the v7 intake journey (create → analysis → scopes → questions → confirm → submit). */
class UserCaseController extends Controller
{
    use FrontResponses;

    public function __construct(private FrontCases $cases, private ActorIdempotency $idempotency) {}

    private function user(Request $r): User
    {
        $u = $r->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }

    private function keyed(Request $r, array $payload, \Closure $op): JsonResponse
    {
        return $r->hasHeader('Idempotency-Key') ? $this->idempotency->run($r, $payload, $op) : $op();
    }

    private function intakeRules(bool $create): array
    {
        return [
            'story' => [$create ? 'required' : 'sometimes', 'string', 'min:10', 'max:10000'], 'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'language' => ['sometimes', Rule::in(['ar', 'en', 'fr', 'tr'])], 'urgency' => ['sometimes', Rule::in(['normal', 'soon', 'urgent'])],
            'residence' => ['sometimes', 'nullable', 'string', 'size:2'], 'caseCountry' => ['sometimes', 'string', 'size:2', Rule::exists('catalog_nodes', 'code')->where('kind', 'country')],
        ];
    }

    public function index(Request $r): JsonResponse
    {
        $cases = $this->user($r)->cases()->with('currentIntake')->where('catalog_contract_version', 2)->orderByDesc('updated_at')->orderByDesc('id')->limit(200)->get();

        return $this->ok($cases->map(fn ($c) => CasePresenter::listItem($c))->all());
    }

    public function show(Request $r, int $case): JsonResponse
    {
        return $this->ok(CasePresenter::case($this->user($r)->cases()->findOrFail($case)));
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate($this->intakeRules(true));

        return $this->keyed($r, $d, fn () => $this->ok(CasePresenter::case($this->cases->create($this->user($r), $d)), 'Case created.', 201));
    }

    public function update(Request $r, int $case): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'summary' => ['sometimes', 'string', 'min:10', 'max:2000']] + $this->intakeRules(false));

        return $this->ok(CasePresenter::case($this->cases->update($this->user($r), $case, $d)), 'Case updated.');
    }

    public function assess(Request $r, int $case): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1']]);
        $result = $this->cases->assess($this->user($r), $case, (int) $d['expectedVersion']);

        return $this->ok(['assessedAt' => $result['assessedAt'], 'suitability' => $result['suitability'], 'case' => CasePresenter::case($result['case'])], 'Case analysed.');
    }

    public function scopes(Request $r, int $case): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'scopes' => ['required', 'array', 'min:1', 'max:20'],
            'scopes.*.catalogEntryId' => ['required', 'integer', 'min:1'], 'scopes.*.confirmed' => ['sometimes', 'boolean'], 'scopes.*.included' => ['sometimes', 'boolean']]);

        return $this->ok(CasePresenter::case($this->cases->scopes($this->user($r), $case, $d)), 'Scopes saved.');
    }

    public function questions(Request $r, int $case): JsonResponse
    {
        return $this->ok(CasePresenter::questionSet($this->user($r)->cases()->findOrFail($case)));
    }

    public function createQuestions(Request $r, int $case): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'regenerate' => ['sometimes', 'boolean']]);

        return $this->ok(CasePresenter::questionSet($this->cases->questionSet($this->user($r), $case, (int) $d['expectedVersion'], (bool) ($d['regenerate'] ?? false))));
    }

    public function answer(Request $r, int $case, int $set): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'responses' => ['required', 'array', 'max:20'],
            'responses.*.questionId' => ['required'], 'responses.*.action' => ['sometimes', Rule::in(['answer', 'skip'])], 'responses.*.answer' => ['nullable']]);
        $c = $this->cases->answer($this->user($r), $case, $set, $d);
        $setData = CasePresenter::questionSet($c);

        return $this->ok(['id' => $setData['id'], 'version' => $setData['version'], 'answers' => $setData['answers']], 'Answers saved.');
    }

    public function confirm(Request $r, int $case): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']]);

        return $this->ok(CasePresenter::case($this->cases->confirm($this->user($r), $case, (int) $d['expectedVersion'])), 'Analysis confirmed.');
    }

    public function submit(Request $r, int $case): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'selectedScopeIds' => ['sometimes', 'array', 'min:1', 'max:20'],
            'selectedScopeIds.*' => ['integer', 'min:1', 'distinct'], 'partialConsent' => ['sometimes', 'boolean']]);

        return $this->keyed($r, $d, fn () => $this->ok(CasePresenter::case($this->cases->submit($this->user($r), $case, $d)), 'Case submitted for matching.'));
    }

    public function cancel(Request $r, int $case): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1']]);

        return $this->ok(CasePresenter::case($this->cases->cancel($this->user($r), $case, (int) $d['expectedVersion'])), 'Case cancelled.');
    }
}
