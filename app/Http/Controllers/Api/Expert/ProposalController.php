<?php

namespace App\Http\Controllers\Api\Expert;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\ExpertCommerceRequest;
use App\Http\Resources\Commerce\CommercePresenter;
use App\Models\Proposal;
use App\Services\Commerce\Proposals;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;

/** Expert-side proposal submission and revision (contract: docs/sprint4/CONTRACT.md). */
class ProposalController extends Controller
{
    use MatchingResponses;

    public function store(ExpertCommerceRequest $r, int $invitation, Proposals $proposals, ActorIdempotency $idempotency): JsonResponse
    {
        return $proposals->auditFailures($r->expert(), 'case_invitation', $invitation, fn () => $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::proposal($proposals->submit($r->expert(), $invitation, $r->validated()), true, false), 'Proposal submitted.', 201)));
    }

    public function index(ExpertCommerceRequest $r): JsonResponse
    {
        $q = Proposal::where('expert_id', $r->expert()->id)->orderByDesc('id');
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }

        return $this->page($q->with('versions.milestones'), $r->validated(), fn ($p) => CommercePresenter::proposal($p, false, false));
    }

    public function show(ExpertCommerceRequest $r, int $proposal): JsonResponse
    {
        return $this->item(CommercePresenter::proposal(Proposal::where('expert_id', $r->expert()->id)->findOrFail($proposal), true, false));
    }

    public function revise(ExpertCommerceRequest $r, int $proposal, Proposals $proposals, ActorIdempotency $idempotency): JsonResponse
    {
        return $proposals->auditFailures($r->expert(), 'proposal', $proposal, fn () => $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::proposal($proposals->revise($r->expert(), $proposal, $r->validated()), true, false), 'Proposal revised.', 201)));
    }

    public function withdraw(ExpertCommerceRequest $r, int $proposal, Proposals $proposals, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::proposal($proposals->withdraw($r->expert(), $proposal, $r->validated()), true, false), 'Proposal withdrawn.'));
    }
}
