<?php

namespace App\Services\Matching;

use App\Exceptions\PrivacyException;
use App\Models\CaseBrief;
use App\Models\CaseInvitation;
use App\Models\CaseRecord;
use App\Models\Expert;
use App\Models\MatchingRun;
use App\Models\MatchingRunCandidate;
use App\Models\User;
use App\Notifications\Matching\InvitationNotice;
use App\Services\Privacy\AuditWriter;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Invitation lifecycle: pending -> accepted | declined | expired | revoked. Matching alone never creates one. */
class Invitations
{
    public const DECLINE_REASONS = ['NOT_AVAILABLE', 'OUTSIDE_EXPERTISE', 'WORKLOAD', 'CONFLICT_OF_INTEREST', 'OTHER'];

    /** Failures worth an audit row even though their transaction is rolled back. */
    private const AUDITED_FAILURES = ['EXPERT_NOT_ELIGIBLE', 'ELIGIBILITY_CHANGED', 'CAPACITY_REACHED', 'MATCHING_RUN_STALE'];

    public function __construct(private MatchEligibility $eligibility, private AccessGrants $grants, private AuditWriter $audit) {}

    /** Run a transactional operation and persist an audit row for important eligibility failures after rollback. */
    public function auditFailures(User|Expert $actor, string $subject, int $subjectId, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (PrivacyException $e) {
            if (in_array($e->errorCode, self::AUDITED_FAILURES, true)) {
                $this->audit->write($actor, 'case.invitation_eligibility_failed', $subject, $subjectId, reason: $e->errorCode);
            }
            throw $e;
        }
    }

    public function create(User $user, int $caseId, array $d): CaseInvitation
    {
        $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
        $run = MatchingRun::where('case_id', $case->id)->findOrFail($d['matchingRunId']);
        $candidate = MatchingRunCandidate::where('run_id', $run->id)->findOrFail($d['candidateId']);
        $brief = CaseBrief::where('case_id', $case->id)->findOrFail($d['briefId']);
        if ($brief->scope_id !== $run->scope_id) {
            throw new PrivacyException('BRIEF_SCOPE_MISMATCH', 'The Case Brief belongs to a different service scope.', 422);
        }
        $scope = $case->serviceScopes()->with('catalogVersion.entry')->findOrFail($run->scope_id);
        $blocks = $this->eligibility->scopeBlocks($case, $scope);
        if ($blocks) {
            throw new PrivacyException($blocks[0], 'This service scope cannot currently receive invitations.');
        }
        if ($run->case_version !== $case->version) {
            throw new PrivacyException('MATCHING_RUN_STALE', 'The matching result no longer describes the current case. Run matching again.');
        }
        if ($brief->case_version !== $case->version) {
            throw new PrivacyException('BRIEF_STALE', 'The Case Brief no longer describes the current case. Approve a new brief.');
        }
        if (! $candidate->eligible) {
            throw new PrivacyException('CANDIDATE_NOT_ELIGIBLE', 'This candidate was not eligible in the matching run.', 422);
        }
        $expert = Expert::findOrFail($candidate->expert_id);
        $current = $this->eligibility->revalidate($expert, $scope->catalogVersion, $candidate->jurisdiction_code, $scope->delivery_mode, $case->currentIntake->payload['language'] ?? null);
        if (! $current['eligible']) {
            throw new PrivacyException('EXPERT_NOT_ELIGIBLE', 'This expert can no longer be invited for this service. Run matching again.');
        }
        $open = CaseInvitation::where('scope_id', $scope->id)->whereIn('status', CaseInvitation::OPEN);
        if ((clone $open)->where('expert_id', $expert->id)->exists()) {
            throw new PrivacyException('INVITATION_ALREADY_ACTIVE', 'This expert already has an open invitation for this service.');
        }
        if ($open->count() >= (int) config('matching.max_open_invitations_per_scope')) {
            throw new PrivacyException('INVITATION_LIMIT_REACHED', 'The maximum number of open invitations for this service has been reached.');
        }
        $inv = new CaseInvitation;
        try {
            $inv->forceFill(['case_id' => $case->id, 'scope_id' => $scope->id, 'expert_id' => $expert->id, 'matching_run_id' => $run->id, 'matching_candidate_id' => $candidate->id, 'brief_id' => $brief->id,
                'jurisdiction_code' => $candidate->jurisdiction_code, 'status' => CaseInvitation::PENDING, 'version' => 1, 'active_key' => CaseInvitation::activeKey($scope->id, $expert->id),
                'expires_at' => now()->addHours((int) config('matching.invitation_ttl_hours'))])->save();
        } catch (UniqueConstraintViolationException) {
            throw new PrivacyException('INVITATION_ALREADY_ACTIVE', 'This expert already has an open invitation for this service.');
        }
        $this->audit->write($user, 'case.invitation_created', 'case_invitation', $inv->id, null, CaseInvitation::PENDING, metadata: ['invitationId' => $inv->id, 'matchingRunId' => $run->id, 'briefId' => $brief->id]);
        $expert->notify(new InvitationNotice('invitation.created', $inv->id));

        return $inv;
    }

    public function accept(Expert $expert, int $id, int $expectedVersion): CaseInvitation
    {
        Expert::query()->whereKey($expert->id)->lockForUpdate()->firstOrFail();
        $inv = $expert->invitations()->whereKey($id)->lockForUpdate()->firstOrFail();
        $this->assertPending($inv, $expectedVersion);
        $case = CaseRecord::findOrFail($inv->case_id);
        $scope = $case->serviceScopes()->with('catalogVersion.entry')->findOrFail($inv->scope_id);
        if ($this->eligibility->scopeBlocks($case, $scope) !== []) {
            throw new PrivacyException('CASE_NOT_AVAILABLE', 'The case is no longer available for this invitation.');
        }
        $current = $this->eligibility->revalidate($expert, $scope->catalogVersion, $inv->jurisdiction_code, $scope->delivery_mode, $case->currentIntake->payload['language'] ?? null, true);
        if (! $current['eligible']) {
            throw new PrivacyException(in_array('CAPACITY_REACHED', $current['exclusionReasons'], true) && count($current['exclusionReasons']) === 1 ? 'CAPACITY_REACHED' : 'ELIGIBILITY_CHANGED', 'You can no longer accept this invitation.');
        }
        $inv->forceFill(['status' => CaseInvitation::ACCEPTED, 'accepted_at' => now(), 'responded_at' => now(), 'version' => $inv->version + 1,
            'proposal_due_at' => now()->addDays((int) config('matching.proposal_window_days'))])->save();
        $this->audit->write($expert, 'case.invitation_accepted', 'case_invitation', $inv->id, CaseInvitation::PENDING, CaseInvitation::ACCEPTED, metadata: ['invitationId' => $inv->id]);
        User::find($case->user_id)?->notify(new InvitationNotice('invitation.accepted', $inv->id));

        return $inv;
    }

    public function decline(Expert $expert, int $id, array $d): CaseInvitation
    {
        Expert::query()->whereKey($expert->id)->lockForUpdate()->firstOrFail();
        $inv = $expert->invitations()->whereKey($id)->lockForUpdate()->firstOrFail();
        $this->assertPending($inv, (int) $d['expectedVersion']);
        $inv->forceFill(['decline_reason_code' => $d['reasonCode'] ?? null, 'decline_note' => isset($d['note']) ? trim(strip_tags($d['note'])) ?: null : null]);
        $this->close($inv, CaseInvitation::DECLINED, null, $expert, 'DECLINED_BY_EXPERT');
        User::find($inv->caseRecord->user_id)?->notify(new InvitationNotice('invitation.declined', $inv->id));

        return $inv;
    }

    public function revoke(User $user, int $caseId, int $id, int $expectedVersion): CaseInvitation
    {
        $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
        $inv = CaseInvitation::where('case_id', $case->id)->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($inv->version !== $expectedVersion) {
            throw new PrivacyException('VERSION_CONFLICT', 'The invitation has changed. Refresh before retrying.');
        }
        if (! $inv->isOpen()) {
            throw new PrivacyException('INVALID_INVITATION_STATE', 'Only an open invitation can be withdrawn.');
        }
        $this->close($inv, CaseInvitation::REVOKED, null, $user, 'REVOKED_BY_USER');
        $inv->expert->notify(new InvitationNotice('invitation.revoked', $inv->id));

        return $inv;
    }

    /** Case cancelled: every open invitation ends and its document access is revoked. */
    public function revokeForCase(CaseRecord $case, ?User $actor): void
    {
        foreach (CaseInvitation::where('case_id', $case->id)->whereIn('status', CaseInvitation::OPEN)->lockForUpdate()->get() as $inv) {
            $this->close($inv, CaseInvitation::REVOKED, null, $actor, 'CASE_CANCELLED');
            $inv->expert->notify(new InvitationNotice('invitation.revoked', $inv->id));
        }
    }

    /** Scheduler entry point. Idempotent: only rows still in the expiring state are touched. @return array{expired: int, expiring: int, grants: int} */
    public function expireDue(): array
    {
        $expired = 0;
        $due = CaseInvitation::where(fn ($q) => $q->where('status', CaseInvitation::PENDING)->where('expires_at', '<=', now())
            ->orWhere(fn ($q) => $q->where('status', CaseInvitation::ACCEPTED)->where('proposal_due_at', '<=', now())->whereNotExists(fn ($p) => $p->select(DB::raw(1))->from('proposals')->whereColumn('proposals.invitation_id', 'case_invitations.id'))))->orderBy('id')->pluck('id');
        foreach ($due as $id) {
            $expired += DB::transaction(function () use ($id) {
                $inv = CaseInvitation::whereKey($id)->lockForUpdate()->first();
                if (! $inv || ! $inv->isOpen() || ($inv->status === CaseInvitation::ACCEPTED && DB::table('proposals')->where('invitation_id', $inv->id)->exists())) {
                    return 0;
                }
                $reason = $inv->status === CaseInvitation::PENDING ? ($inv->expires_at->isPast() ? 'INVITATION_DEADLINE' : null) : ($inv->proposal_due_at?->isPast() ? 'PROPOSAL_WINDOW' : null);
                if (! $reason) {
                    return 0;
                }
                $this->close($inv, CaseInvitation::EXPIRED, $reason, null, $reason);
                $inv->expert->notify(new InvitationNotice('invitation.expired', $inv->id));
                User::find($inv->caseRecord->user_id)?->notify(new InvitationNotice('invitation.expired', $inv->id));

                return 1;
            });
        }
        $expiring = 0;
        $soon = CaseInvitation::where('status', CaseInvitation::PENDING)->whereNull('expiring_notified_at')->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addHours((int) config('matching.expiring_notice_hours')))->pluck('id');
        foreach ($soon as $id) {
            $expiring += DB::transaction(function () use ($id) {
                $inv = CaseInvitation::whereKey($id)->lockForUpdate()->first();
                if (! $inv || $inv->status !== CaseInvitation::PENDING || $inv->expiring_notified_at) {
                    return 0;
                }
                $inv->forceFill(['expiring_notified_at' => now()])->save();
                $inv->expert->notify(new InvitationNotice('invitation.expiring', $inv->id));

                return 1;
            });
        }
        $grants = DB::transaction(fn () => $this->grants->expireElapsed());

        return ['expired' => $expired, 'expiring' => $expiring, 'grants' => $grants];
    }

    private function assertPending(CaseInvitation $inv, int $expectedVersion): void
    {
        if ($inv->version !== $expectedVersion) {
            throw new PrivacyException('VERSION_CONFLICT', 'The invitation has changed. Refresh before retrying.');
        }
        if ($inv->status !== CaseInvitation::PENDING) {
            throw new PrivacyException('INVALID_INVITATION_STATE', 'This invitation can no longer be answered.');
        }
        if ($inv->expires_at->isPast()) {
            throw new PrivacyException('INVITATION_EXPIRED', 'This invitation has expired.');
        }
    }

    /** Ends an invitation and revokes its document access. Also used by the Sprint 4 proposal outcomes. */
    public function close(CaseInvitation $inv, string $status, ?string $expiryReason, User|Expert|null $actor, string $reason): void
    {
        $from = $inv->status;
        $inv->forceFill(['status' => $status, 'expiry_reason' => $expiryReason, 'active_key' => null, 'closed_at' => now(), 'responded_at' => $inv->responded_at ?? ($actor ? now() : null), 'version' => $inv->version + 1])->save();
        $this->grants->revokeForInvitation($inv, 'INVITATION_'.strtoupper($status), $actor instanceof User ? $actor : null);
        $this->audit->write($actor, 'case.invitation_'.$status, 'case_invitation', $inv->id, $from, $status, $reason, ['invitationId' => $inv->id]);
    }
}
