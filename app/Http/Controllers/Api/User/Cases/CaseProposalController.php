<?php

namespace App\Http\Controllers\Api\User\Cases;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\CommerceRequest;
use App\Http\Resources\Commerce\CommercePresenter;
use App\Models\Proposal;
use App\Services\Commerce\Proposals;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;

/** User-side proposal review, negotiation and acceptance (contract: docs/sprint4/CONTRACT.md). */
class CaseProposalController extends Controller
{
    use MatchingResponses;

    public function index(CommerceRequest $r, int $case): JsonResponse
    {
        $row = $r->user()->cases()->findOrFail($case);
        $q = Proposal::where('case_id', $row->id)->orderByDesc('id');
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }

        return $this->page($q->with('versions.milestones', 'expert.profile'), $r->validated(), fn ($p) => CommercePresenter::proposal($p));
    }

    public function show(CommerceRequest $r, int $case, int $proposal): JsonResponse
    {
        $row = $r->user()->cases()->findOrFail($case);

        return $this->item(CommercePresenter::proposal(Proposal::where('case_id', $row->id)->findOrFail($proposal), true));
    }

    public function requestChange(CommerceRequest $r, int $case, int $proposal, Proposals $proposals, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::proposal($proposals->requestChange($r->user(), $case, $proposal, $r->validated()), true), 'Change request sent.', 201));
    }

    public function accept(CommerceRequest $r, int $case, int $proposal, Proposals $proposals, ActorIdempotency $idempotency): JsonResponse
    {
        return $proposals->auditFailures($r->user(), 'proposal', $proposal, fn () => $idempotency->run($r, $r->validated(), function () use ($r, $case, $proposal, $proposals): JsonResponse {
            [$p, $agreement] = $proposals->accept($r->user(), $case, $proposal, $r->validated());

            return $this->item(['proposal' => CommercePresenter::proposal($p), 'agreement' => CommercePresenter::agreement($agreement)], 'Proposal accepted.');
        }));
    }

    public function reject(CommerceRequest $r, int $case, int $proposal, Proposals $proposals, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::proposal($proposals->reject($r->user(), $case, $proposal, $r->validated())), 'Proposal rejected.'));
    }
}
