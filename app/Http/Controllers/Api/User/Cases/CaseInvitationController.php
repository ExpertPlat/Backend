<?php

namespace App\Http\Controllers\Api\User\Cases;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matching\MatchingRequest;
use App\Http\Resources\Matching\MatchingPresenter;
use App\Models\CaseInvitation;
use App\Services\Matching\AccessGrants;
use App\Services\Matching\Invitations;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;

/** User-side invitations and Expert document access grants. */
class CaseInvitationController extends Controller
{
    use MatchingResponses;

    public function store(MatchingRequest $r, int $case, Invitations $invitations, ActorIdempotency $idempotency): JsonResponse
    {
        return $invitations->auditFailures($r->user(), 'case', $case, fn () => $idempotency->run($r, $r->validated(), fn () => $this->item(
            MatchingPresenter::invitationForUser($invitations->create($r->user(), $case, $r->validated())), 'Invitation sent.', 201)));
    }

    public function index(MatchingRequest $r, int $case): JsonResponse
    {
        $row = $r->user()->cases()->findOrFail($case);
        $q = CaseInvitation::where('case_id', $row->id)->orderByDesc('id');
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }

        return $this->page($q->with('expert.profile', 'brief'), $r->validated(), fn ($i) => MatchingPresenter::invitationForUser($i));
    }

    public function show(MatchingRequest $r, int $case, int $invitation): JsonResponse
    {
        $row = $r->user()->cases()->findOrFail($case);

        return $this->item(MatchingPresenter::invitationForUser(CaseInvitation::where('case_id', $row->id)->findOrFail($invitation)));
    }

    public function revoke(MatchingRequest $r, int $case, int $invitation, Invitations $invitations, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), fn () => $this->item(
            MatchingPresenter::invitationForUser($invitations->revoke($r->user(), $case, $invitation, (int) $r->validated('expectedVersion'))), 'Invitation withdrawn.'));
    }

    public function grantDocuments(MatchingRequest $r, int $case, int $invitation, AccessGrants $grants, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), fn () => $this->items($grants->grant($r->user(), $case, $invitation, $r->validated('documentVersionIds'))
            ->map(fn ($g) => MatchingPresenter::grant($g))->values()->all())->setStatusCode(201));
    }

    public function indexGrants(MatchingRequest $r, int $case, int $invitation): JsonResponse
    {
        $row = $r->user()->cases()->findOrFail($case);
        $inv = CaseInvitation::where('case_id', $row->id)->findOrFail($invitation);

        return $this->items($inv->grants()->orderBy('id')->get()->map(fn ($g) => MatchingPresenter::grant($g))->all());
    }

    public function revokeGrant(MatchingRequest $r, int $case, int $invitation, int $grant, AccessGrants $grants, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), fn () => $this->item(MatchingPresenter::grant($grants->revoke($r->user(), $case, $invitation, $grant)), 'Document access revoked.'));
    }
}
