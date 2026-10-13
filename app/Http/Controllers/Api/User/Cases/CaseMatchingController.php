<?php

namespace App\Http\Controllers\Api\User\Cases;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matching\MatchingRequest;
use App\Http\Resources\Matching\MatchingPresenter;
use App\Models\CaseBrief;
use App\Models\MatchingRun;
use App\Services\Matching\CaseBriefs;
use App\Services\Matching\MatchingEngine;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;

/** Matching runs and Case Briefs (both are scope-bound and owner-only). */
class CaseMatchingController extends Controller
{
    use MatchingResponses;

    public function storeRun(MatchingRequest $r, int $case, int $scope, MatchingEngine $engine, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), function () use ($r, $case, $scope, $engine) {
            $run = $engine->create($r->user(), $case, $scope, (int) $r->validated('expectedVersion'));
            $row = $r->user()->cases()->findOrFail($case);

            return $this->item(MatchingPresenter::run($run, $engine->staleReasons($run, $row, $row->serviceScopes()->findOrFail($scope))), 'Matching run completed.', 201);
        });
    }

    public function indexRuns(MatchingRequest $r, int $case, int $scope, MatchingEngine $engine): JsonResponse
    {
        $row = $r->user()->cases()->findOrFail($case);
        $s = $row->serviceScopes()->findOrFail($scope);

        return $this->page(MatchingRun::where(['case_id' => $row->id, 'scope_id' => $s->id])->orderByDesc('id'), $r->validated(), fn ($run) => MatchingPresenter::run($run, $engine->staleReasons($run, $row, $s), false));
    }

    public function showRun(MatchingRequest $r, int $case, int $scope, int $run, MatchingEngine $engine): JsonResponse
    {
        $row = $r->user()->cases()->findOrFail($case);
        $s = $row->serviceScopes()->findOrFail($scope);
        $model = MatchingRun::where(['case_id' => $row->id, 'scope_id' => $s->id])->findOrFail($run);

        return $this->item(MatchingPresenter::run($model, $engine->staleReasons($model, $row, $s)));
    }

    public function previewBrief(MatchingRequest $r, int $case, int $scope, CaseBriefs $briefs): JsonResponse
    {
        $built = $briefs->preview($r->user(), $case, $scope, $r->validated());

        return $this->item(['content' => MatchingPresenter::content($built['payload']), 'contentHash' => $built['hash'], 'caseVersion' => $built['caseVersion'],
            'contextSnapshotIds' => $built['contextSnapshotIds'], 'documentVersionIds' => $built['documentVersionIds']], 'Case Brief preview. Nothing has been shared yet.');
    }

    public function storeBrief(MatchingRequest $r, int $case, int $scope, CaseBriefs $briefs, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), fn () => $this->item(MatchingPresenter::brief($briefs->approve($r->user(), $case, $scope, $r->validated())), 'Case Brief approved.', 201));
    }

    public function indexBriefs(MatchingRequest $r, int $case, int $scope): JsonResponse
    {
        $s = $r->user()->cases()->findOrFail($case)->serviceScopes()->findOrFail($scope);

        return $this->page(CaseBrief::where(['case_id' => $s->case_id, 'scope_id' => $s->id])->orderByDesc('id'), $r->validated(), fn ($b) => MatchingPresenter::brief($b, false));
    }

    public function showBrief(MatchingRequest $r, int $case, int $scope, int $brief): JsonResponse
    {
        $s = $r->user()->cases()->findOrFail($case)->serviceScopes()->findOrFail($scope);

        return $this->item(MatchingPresenter::brief(CaseBrief::where(['case_id' => $s->case_id, 'scope_id' => $s->id])->findOrFail($brief)));
    }
}
