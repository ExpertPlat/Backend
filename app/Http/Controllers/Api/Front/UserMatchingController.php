<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Http\Front\FrontResponses;
use App\Http\Front\Presenters\MatchingPresenter;
use App\Models\CaseInvitation;
use App\Models\User;
use App\Services\Matching\AccessGrants;
use App\Services\Matching\CaseBriefs;
use App\Services\Matching\Invitations;
use App\Services\Matching\MatchingEngine;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Matching runs, Case Briefs and invitations for the User (v7 shapes). */
class UserMatchingController extends Controller
{
    use FrontResponses;

    public function __construct(private ActorIdempotency $idempotency) {}

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

    public function storeRun(Request $r, int $case, int $scope, MatchingEngine $engine): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1']]);
        $u = $this->user($r);
        $run = $engine->create($u, $case, $scope, (int) $d['expectedVersion']);
        $row = $u->cases()->with('analysis')->findOrFail($case);

        return $this->ok(MatchingPresenter::run($run, $engine->staleReasons($run, $row, $row->serviceScopes()->findOrFail($scope)), MatchingPresenter::scopeScore($row, $scope)), 'Matching run completed.', 201);
    }

    private function briefInput(Request $r, User $u, int $case): array
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1'], 'contextSnapshotIds' => ['sometimes', 'array', 'max:20'], 'contextSnapshotIds.*' => ['integer'],
            'documentIds' => ['sometimes', 'array', 'max:20'], 'documentIds.*' => ['integer'], 'followUpKeys' => ['sometimes', 'array'],
            'approved' => ['sometimes', 'accepted'], 'contentHash' => ['sometimes', 'string', 'size:64']]);
        $intake = $u->cases()->with('currentIntake')->findOrFail($case)->currentIntake->payload ?? [];
        // The User approved the whole analysis (summary + answers) on the previous screen: every answered question is shared.
        $d['followUpKeys'] = array_keys($intake['followUpAnswers'] ?? []);
        $d['contextSnapshotIds'] ??= [];
        $d['documentIds'] ??= [];

        return $d;
    }

    public function previewBrief(Request $r, int $case, int $scope, CaseBriefs $briefs): JsonResponse
    {
        $u = $this->user($r);

        return $this->ok(MatchingPresenter::preview($briefs->preview($u, $case, $scope, $this->briefInput($r, $u, $case))), 'Case Brief preview. Nothing has been shared yet.');
    }

    public function storeBrief(Request $r, int $case, int $scope, CaseBriefs $briefs): JsonResponse
    {
        $u = $this->user($r);
        $d = $this->briefInput($r, $u, $case);
        abort_unless(($d['approved'] ?? false) && isset($d['contentHash']), 422, 'The brief must be approved.');
        $sharing = $u->consents()->where('purpose', 'expert_sharing')->orderByDesc('id')->first();
        if ($sharing && in_array($sharing->decision->value, ['declined', 'withdrawn'], true)) {
            throw new \App\Exceptions\PrivacyException('EXPERT_SHARING_DISABLED', \App\Http\Front\Front::t(['ar' => 'أوقفت مشاركة حالتك مع الخبراء من الإعدادات. فعّلها لإرسال الدعوة.', 'en' => 'You turned off sharing with experts in settings. Turn it on to send the invitation.']));
        }

        return $this->keyed($r, $d, fn () => $this->ok(MatchingPresenter::brief($briefs->approve($u, $case, $scope, $d)), 'Case Brief approved.', 201));
    }

    public function invitations(Request $r, int $case): JsonResponse
    {
        $c = $this->user($r)->cases()->findOrFail($case);

        return $this->ok(CaseInvitation::where('case_id', $c->id)->orderByDesc('id')->get()->map(fn ($i) => MatchingPresenter::forUser($i))->all());
    }

    public function invitation(Request $r, int $case, int $invitation): JsonResponse
    {
        $c = $this->user($r)->cases()->findOrFail($case);

        return $this->ok(MatchingPresenter::forUser(CaseInvitation::where('case_id', $c->id)->findOrFail($invitation)));
    }

    public function invite(Request $r, int $case, Invitations $invitations, AccessGrants $grants): JsonResponse
    {
        $d = $r->validate(['matchingRunId' => ['required', 'integer', 'min:1'], 'candidateId' => ['required', 'integer', 'min:1'], 'briefId' => ['required', 'integer', 'min:1'],
            'message' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        $u = $this->user($r);

        return $this->keyed($r, $d, fn () => DB::transaction(function () use ($u, $case, $d, $invitations, $grants) {
            $inv = $invitations->auditFailures($u, 'case', $case, fn () => $invitations->create($u, $case, $d));
            if (filled($d['message'] ?? null)) {
                $inv->forceFill(['message' => trim(strip_tags($d['message']))])->save();
            }
            // Documents the User chose to share in the brief are granted to the invited Expert (time-limited, revocable).
            $versions = $inv->brief->document_version_ids ?? [];
            if ($versions) {
                $grants->grant($u, $case, $inv->id, $versions);
            }

            return $this->ok(MatchingPresenter::forUser($inv->fresh()), 'Invitation sent.', 201);
        }));
    }

    public function revoke(Request $r, int $case, int $invitation, Invitations $invitations): JsonResponse
    {
        $d = $r->validate(['expectedVersion' => ['required', 'integer', 'min:1']]);

        return $this->ok(MatchingPresenter::forUser($invitations->revoke($this->user($r), $case, $invitation, (int) $d['expectedVersion'])), 'Invitation revoked.');
    }
}
