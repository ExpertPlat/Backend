<?php

namespace App\Http\Controllers\Api\User\Cases;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matching\MatchingRequest;
use App\Http\Resources\Matching\MatchingPresenter;
use App\Models\CaseAiQuestionSet;
use App\Services\Cases\AiIntakeQuestions;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;

class AiQuestionController extends Controller
{
    use MatchingResponses;

    public function store(MatchingRequest $r, int $case, AiIntakeQuestions $ai): JsonResponse
    {
        $result = $ai->generate($r->user(), $case, $this->idempotencyKey(), $r->validated());
        $row = $r->user()->cases()->with('currentIntake')->findOrFail($case);
        $response = $this->item(MatchingPresenter::aiSet($result['set'], $row, $result['reused']), 'AI-assisted follow-up questions generated.', $result['status']);

        return $result['replayed'] ? $response->header('Idempotency-Replayed', 'true') : $response;
    }

    public function index(MatchingRequest $r, int $case): JsonResponse
    {
        $row = $r->user()->cases()->with('currentIntake')->findOrFail($case);
        $sets = CaseAiQuestionSet::where('case_id', $row->id)->where('status', 'completed')->latest('id')->limit(10)->with('questions')->get();

        return $this->items($sets->map(fn ($s) => MatchingPresenter::aiSet($s, $row))->all());
    }

    public function respond(MatchingRequest $r, int $case, int $set, AiIntakeQuestions $ai, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), function () use ($r, $case, $set, $ai) {
            $result = $ai->respond($r->user(), $case, $set, $r->validated());
            $row = $r->user()->cases()->with('currentIntake')->findOrFail($case);

            return $this->item(['caseVersion' => $result['caseVersion']] + MatchingPresenter::aiSet($result['set'], $row), 'Your responses were saved.');
        });
    }
}
