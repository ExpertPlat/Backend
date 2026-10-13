<?php

namespace App\Http\Controllers\Api\Expert;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matching\ExpertMatchingRequest;
use App\Http\Resources\Matching\MatchingPresenter;
use App\Models\CaseInvitation;
use App\Services\Matching\AccessGrants;
use App\Services\Matching\Invitations;
use App\Services\Matching\MatchEligibility;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class InvitationController extends Controller
{
    use MatchingResponses;

    public function index(ExpertMatchingRequest $r): JsonResponse
    {
        $q = $r->expert()->invitations()->orderByDesc('id');
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }

        return $this->page($q->with('scope.catalogVersion', 'caseRecord.currentIntake', 'brief'), $r->validated(), fn ($i) => MatchingPresenter::invitationForExpert($i));
    }

    public function show(ExpertMatchingRequest $r, int $invitation, MatchEligibility $eligibility): JsonResponse
    {
        $i = $r->expert()->invitations()->findOrFail($invitation);
        $live = null;
        if ($i->isOpen()) {
            $scope = $i->scope;
            $result = $eligibility->revalidate($r->expert(), $scope->catalogVersion, $i->jurisdiction_code, $scope->delivery_mode, $i->caseRecord->currentIntake->payload['language'] ?? null);
            $live = ['eligible' => $result['eligible'], 'reasonCodes' => $result['exclusionReasons']];
        }

        return $this->item(MatchingPresenter::invitationForExpert($i, true, $live));
    }

    public function accept(ExpertMatchingRequest $r, int $invitation, Invitations $invitations, ActorIdempotency $idempotency): JsonResponse
    {
        return $invitations->auditFailures($r->expert(), 'case_invitation', $invitation, fn () => $idempotency->run($r, $r->validated(), fn () => $this->item(
            MatchingPresenter::invitationForExpert($invitations->accept($r->expert(), $invitation, (int) $r->validated('expectedVersion')), true), 'Invitation accepted.')));
    }

    public function decline(ExpertMatchingRequest $r, int $invitation, Invitations $invitations, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), fn () => $this->item(
            MatchingPresenter::invitationForExpert($invitations->decline($r->expert(), $invitation, $r->validated()), true), 'Invitation declined.'));
    }

    public function documents(ExpertMatchingRequest $r, int $invitation): JsonResponse
    {
        $i = $r->expert()->invitations()->findOrFail($invitation);
        if ($i->status !== CaseInvitation::ACCEPTED) {
            return response()->json(['status' => false, 'code' => 'INVITATION_NOT_ACCEPTED', 'message' => 'Document metadata is available after you accept the invitation.'], 409);
        }
        $grants = $i->grants()->get()->keyBy('document_version_id');
        $documents = collect($i->brief->payload['documents'] ?? [])->map(fn ($d) => $d + ['grant' => ($g = $grants->get($d['versionId'])) ? MatchingPresenter::grant($g, true) : null]);

        return $this->items($documents->values()->all());
    }

    public function download(ExpertMatchingRequest $r, int $invitation, int $grant, AccessGrants $grants): Response
    {
        $file = $grants->download($r->expert(), $invitation, $grant);
        $v = $file['version'];

        return response($file['bytes'], 200, ['Content-Type' => $v->mime_type, 'Content-Disposition' => 'attachment; filename="case-document-'.$v->id.'.'.$v->extension.'"',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store']);
    }
}
