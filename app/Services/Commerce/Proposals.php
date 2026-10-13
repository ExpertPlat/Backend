<?php

namespace App\Services\Commerce;

use App\Enums\CaseStatus;
use App\Exceptions\PrivacyException;
use App\Models\Agreement;
use App\Models\AgreementMilestone;
use App\Models\CaseBrief;
use App\Models\CaseInvitation;
use App\Models\CaseRecord;
use App\Models\Expert;
use App\Models\Proposal;
use App\Models\ProposalChangeRequest;
use App\Models\ProposalEvent;
use App\Models\ProposalMilestone;
use App\Models\ProposalVersion;
use App\Models\User;
use App\Notifications\Commerce\CommerceNotice;
use App\Services\Matching\Invitations;
use App\Services\Matching\MatchEligibility;
use App\Services\Privacy\AuditWriter;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Proposal lifecycle (docs/sprint4/CONTRACT.md section 3). Versions are immutable; accepting one creates the
 * Agreement Snapshot in `awaiting_payment`. Nothing here touches payment, funding or delivery state.
 */
class Proposals
{
    use AuditsCommerceFailures;

    public const CHANGE_CATEGORIES = ['price', 'scope', 'deliverables', 'timeline', 'other'];

    public const REJECT_REASONS = ['TERMS_NOT_ACCEPTABLE', 'FOUND_ALTERNATIVE', 'NO_LONGER_NEEDED', 'OTHER'];

    public const WITHDRAW_REASONS = ['NOT_AVAILABLE', 'SCOPE_NOT_FEASIBLE', 'OTHER'];

    public function __construct(private MatchEligibility $eligibility, private Invitations $invitations, private AuditWriter $audit) {}

    public function submit(Expert $expert, int $invitationId, array $d): Proposal
    {
        return DB::transaction(function () use ($expert, $invitationId, $d): Proposal {
            Expert::query()->whereKey($expert->id)->lockForUpdate()->firstOrFail();
            $inv = $expert->invitations()->whereKey($invitationId)->lockForUpdate()->firstOrFail();
            if ($inv->status !== CaseInvitation::ACCEPTED || ! $inv->proposal_due_at || $inv->proposal_due_at->isPast()) {
                throw new PrivacyException('PROPOSAL_NOT_ALLOWED', 'A proposal can only be submitted for an accepted invitation within its proposal window.');
            }
            if (Proposal::where('invitation_id', $inv->id)->exists()) {
                throw new PrivacyException('PROPOSAL_ALREADY_EXISTS', 'A proposal already exists for this invitation. Submit a new version instead.');
            }
            $this->assertEligible($expert, $inv);
            $content = $this->normalize($d);
            $validUntil = now()->addDays((int) ($d['validityDays'] ?? config('commerce.proposal_validity_days')));
            $proposal = new Proposal;
            try {
                $proposal->forceFill(['case_id' => $inv->case_id, 'scope_id' => $inv->scope_id, 'invitation_id' => $inv->id, 'expert_id' => $expert->id, 'user_id' => $inv->caseRecord->user_id,
                    'brief_id' => $inv->brief_id, 'status' => Proposal::SUBMITTED, 'current_version_no' => 1, 'version' => 1, 'valid_until' => $validUntil])->save();
            } catch (UniqueConstraintViolationException) {
                throw new PrivacyException('PROPOSAL_ALREADY_EXISTS', 'A proposal already exists for this invitation. Submit a new version instead.');
            }
            $this->storeVersion($proposal, 1, $content, $validUntil);
            $this->event($proposal, 'submitted', $expert, 1);
            $this->audit->write($expert, 'proposal.submitted', 'proposal', $proposal->id, null, Proposal::SUBMITTED, metadata: ['proposalId' => $proposal->id, 'proposalVersion' => 1, 'invitationId' => $inv->id]);
            User::find($proposal->user_id)?->notify(new CommerceNotice('proposal.submitted', ['proposalId' => $proposal->id, 'caseId' => $proposal->case_id]));

            return $proposal;
        });
    }

    public function revise(Expert $expert, int $id, array $d): Proposal
    {
        return DB::transaction(function () use ($expert, $id, $d): Proposal {
            Expert::query()->whereKey($expert->id)->lockForUpdate()->firstOrFail();
            $proposal = Proposal::where('expert_id', $expert->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($proposal, (int) $d['expectedVersion']);
            $this->assertLive($proposal);
            $inv = CaseInvitation::whereKey($proposal->invitation_id)->lockForUpdate()->firstOrFail();
            if ($inv->status !== CaseInvitation::ACCEPTED) {
                throw new PrivacyException('INVALID_PROPOSAL_STATE', 'The invitation behind this proposal is no longer open.');
            }
            if ($proposal->current_version_no >= (int) config('commerce.max_proposal_versions')) {
                throw new PrivacyException('PROPOSAL_VERSION_LIMIT', 'The maximum number of proposal versions has been reached.');
            }
            $this->assertEligible($expert, $inv);
            $content = $this->normalize($d);
            $next = $proposal->current_version_no + 1;
            $validUntil = now()->addDays((int) ($d['validityDays'] ?? config('commerce.proposal_validity_days')));
            $this->storeVersion($proposal, $next, $content, $validUntil);
            ProposalChangeRequest::where('proposal_id', $proposal->id)->where('status', 'open')->update(['status' => 'addressed', 'resolved_by_version' => $next, 'updated_at' => now()]);
            $from = $proposal->status;
            $proposal->forceFill(['status' => Proposal::SUBMITTED, 'current_version_no' => $next, 'valid_until' => $validUntil, 'version' => $proposal->version + 1])->save();
            $this->event($proposal, 'revised', $expert, $next);
            $this->audit->write($expert, 'proposal.revised', 'proposal', $proposal->id, $from, Proposal::SUBMITTED, metadata: ['proposalId' => $proposal->id, 'proposalVersion' => $next]);
            User::find($proposal->user_id)?->notify(new CommerceNotice('proposal.revised', ['proposalId' => $proposal->id, 'caseId' => $proposal->case_id]));

            return $proposal;
        });
    }

    public function requestChange(User $user, int $caseId, int $id, array $d): Proposal
    {
        return DB::transaction(function () use ($user, $caseId, $id, $d): Proposal {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            $proposal = Proposal::where('case_id', $case->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($proposal, (int) $d['expectedVersion']);
            if ($proposal->status !== Proposal::SUBMITTED) {
                throw new PrivacyException('INVALID_PROPOSAL_STATE', 'A change can only be requested on a submitted proposal.');
            }
            $this->assertNotExpired($proposal);
            if ((int) $d['versionNo'] !== $proposal->current_version_no) {
                throw new PrivacyException('PROPOSAL_VERSION_STALE', 'This is not the latest proposal version.');
            }
            $request = new ProposalChangeRequest;
            $request->forceFill(['proposal_id' => $proposal->id, 'version_no' => $proposal->current_version_no, 'user_id' => $user->id, 'categories' => array_values(array_unique($d['categories'])),
                'message' => trim(strip_tags($d['message'])), 'status' => 'open'])->save();
            $proposal->forceFill(['status' => Proposal::CHANGE_REQUESTED, 'version' => $proposal->version + 1])->save();
            $this->event($proposal, 'change_requested', $user, $proposal->current_version_no);
            $this->audit->write($user, 'proposal.change_requested', 'proposal', $proposal->id, Proposal::SUBMITTED, Proposal::CHANGE_REQUESTED, metadata: ['proposalId' => $proposal->id, 'proposalVersion' => $proposal->current_version_no]);
            $proposal->expert->notify(new CommerceNotice('proposal.change_requested', ['proposalId' => $proposal->id]));

            return $proposal;
        });
    }

    /** @return array{0: Proposal, 1: Agreement} */
    public function accept(User $user, int $caseId, int $id, array $d): array
    {
        return DB::transaction(function () use ($user, $caseId, $id, $d): array {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            // Lock order (deadlock safety): case, expert, proposal, invitation.
            Expert::query()->whereKey(Proposal::where('case_id', $case->id)->whereKey($id)->value('expert_id') ?? 0)->lockForUpdate()->first();
            $proposal = Proposal::where('case_id', $case->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($proposal, (int) $d['expectedVersion']);
            $this->assertLive($proposal);
            $this->assertNotExpired($proposal);
            if ((int) $d['versionNo'] !== $proposal->current_version_no) {
                throw new PrivacyException('PROPOSAL_VERSION_STALE', 'This is not the latest proposal version.');
            }
            $version = ProposalVersion::where('proposal_id', $proposal->id)->where('version_no', $proposal->current_version_no)->with('milestones')->firstOrFail();
            if ($version->total_amount_minor !== $d['totalAmountMinor'] || $version->currency !== $d['currency']) {
                throw new PrivacyException('PROPOSAL_TERMS_CHANGED', 'The proposal terms differ from what was reviewed. Review the latest version.');
            }
            $inv = CaseInvitation::whereKey($proposal->invitation_id)->lockForUpdate()->firstOrFail();
            if ($inv->status !== CaseInvitation::ACCEPTED) {
                throw new PrivacyException('INVALID_PROPOSAL_STATE', 'The invitation behind this proposal is no longer open.');
            }
            $this->assertEligible($proposal->expert, $inv, $case);
            try {
                $agreement = $this->createAgreement($case, $proposal, $version);
            } catch (UniqueConstraintViolationException) {
                throw new PrivacyException('INVALID_PROPOSAL_STATE', 'This proposal has already been accepted.');
            }
            $proposal->forceFill(['status' => Proposal::ACCEPTED, 'closed_at' => now(), 'version' => $proposal->version + 1])->save();
            $this->event($proposal, 'accepted', $user, $version->version_no);
            $this->audit->write($user, 'proposal.accepted', 'proposal', $proposal->id, Proposal::SUBMITTED, Proposal::ACCEPTED, metadata: ['proposalId' => $proposal->id, 'proposalVersion' => $version->version_no, 'agreementId' => $agreement->id]);
            $this->audit->write($user, 'agreement.created', 'agreement', $agreement->id, null, Agreement::AWAITING_PAYMENT, metadata: ['agreementId' => $agreement->id, 'proposalId' => $proposal->id, 'proposalVersion' => $version->version_no]);
            $proposal->expert->notify(new CommerceNotice('proposal.accepted', ['proposalId' => $proposal->id, 'agreementId' => $agreement->id]));

            return [$proposal, $agreement];
        });
    }

    public function reject(User $user, int $caseId, int $id, array $d): Proposal
    {
        return DB::transaction(function () use ($user, $caseId, $id, $d): Proposal {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            $proposal = Proposal::where('case_id', $case->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($proposal, (int) $d['expectedVersion']);
            $this->assertLive($proposal);
            $this->terminate($proposal, Proposal::REJECTED, 'rejected', $user, $d['reasonCode'] ?? null);
            $inv = CaseInvitation::whereKey($proposal->invitation_id)->lockForUpdate()->firstOrFail();
            if ($inv->isOpen()) {
                $this->invitations->close($inv, CaseInvitation::REVOKED, null, $user, 'PROPOSAL_REJECTED');
            }
            $proposal->expert->notify(new CommerceNotice('proposal.rejected', ['proposalId' => $proposal->id]));

            return $proposal;
        });
    }

    public function withdraw(Expert $expert, int $id, array $d): Proposal
    {
        return DB::transaction(function () use ($expert, $id, $d): Proposal {
            Expert::query()->whereKey($expert->id)->lockForUpdate()->firstOrFail();
            $proposal = Proposal::where('expert_id', $expert->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($proposal, (int) $d['expectedVersion']);
            $this->assertLive($proposal);
            $this->terminate($proposal, Proposal::WITHDRAWN, 'withdrawn', $expert, $d['reasonCode'] ?? null);
            $inv = CaseInvitation::whereKey($proposal->invitation_id)->lockForUpdate()->firstOrFail();
            if ($inv->isOpen()) {
                $inv->forceFill(['decline_reason_code' => 'OTHER']);
                $this->invitations->close($inv, CaseInvitation::DECLINED, null, $expert, 'PROPOSAL_WITHDRAWN');
            }
            User::find($proposal->user_id)?->notify(new CommerceNotice('proposal.withdrawn', ['proposalId' => $proposal->id, 'caseId' => $proposal->case_id]));

            return $proposal;
        });
    }

    /** Scheduler entry point. Idempotent: only proposals still live and past their validity are touched. */
    public function expireDue(): int
    {
        $expired = 0;
        foreach (Proposal::whereIn('status', Proposal::LIVE)->where('valid_until', '<=', now())->orderBy('id')->pluck('id') as $id) {
            $expired += DB::transaction(function () use ($id): int {
                $proposal = Proposal::whereKey($id)->lockForUpdate()->first();
                if (! $proposal || ! $proposal->isLive() || $proposal->valid_until->isFuture()) {
                    return 0;
                }
                $this->terminate($proposal, Proposal::EXPIRED, 'expired', null, 'PROPOSAL_EXPIRED');
                $inv = CaseInvitation::whereKey($proposal->invitation_id)->lockForUpdate()->first();
                if ($inv?->isOpen()) {
                    $this->invitations->close($inv, CaseInvitation::EXPIRED, 'PROPOSAL_EXPIRED', null, 'PROPOSAL_EXPIRED');
                }
                $proposal->expert->notify(new CommerceNotice('proposal.expired', ['proposalId' => $proposal->id]));
                User::find($proposal->user_id)?->notify(new CommerceNotice('proposal.expired', ['proposalId' => $proposal->id, 'caseId' => $proposal->case_id]));

                return 1;
            });
        }

        return $expired;
    }

    /** Case cancelled: live proposals end, awaiting_payment agreements are cancelled and their bookings released. */
    public function cancelForCase(CaseRecord $case, ?User $actor): void
    {
        DB::transaction(function () use ($case, $actor): void {
            // Lock order: experts (sorted) before proposals/agreements, matching every Expert-side operation.
            $experts = Proposal::where('case_id', $case->id)->pluck('expert_id')->merge(Agreement::where('case_id', $case->id)->pluck('expert_id'))->unique()->sort()->values();
            foreach ($experts as $expertId) {
                Expert::query()->whereKey($expertId)->lockForUpdate()->first();
            }
            foreach (Proposal::where('case_id', $case->id)->whereIn('status', Proposal::LIVE)->lockForUpdate()->get() as $proposal) {
                $this->terminate($proposal, Proposal::WITHDRAWN, 'withdrawn', $actor, 'CASE_CANCELLED');
                $proposal->expert->notify(new CommerceNotice('proposal.withdrawn', ['proposalId' => $proposal->id]));
            }
            foreach (Agreement::where('case_id', $case->id)->whereIn('status', [Agreement::AWAITING_PAYMENT, Agreement::FUNDING_EXPIRED])->lockForUpdate()->get() as $agreement) {
                app(Agreements::class)->cancelRow($agreement, $actor, 'CASE_CANCELLED', false);
            }
        });
    }

    private function terminate(Proposal $proposal, string $status, string $event, User|Expert|null $actor, ?string $reason): void
    {
        $from = $proposal->status;
        $proposal->forceFill(['status' => $status, 'closed_at' => now(), 'version' => $proposal->version + 1])->save();
        $this->event($proposal, $event, $actor, $proposal->current_version_no, $reason);
        $this->audit->write($actor, 'proposal.'.$event, 'proposal', $proposal->id, $from, $status, $reason, ['proposalId' => $proposal->id, 'proposalVersion' => $proposal->current_version_no]);
    }

    private function assertVersion(Proposal $proposal, int $expected): void
    {
        if ($proposal->version !== $expected) {
            throw new PrivacyException('VERSION_CONFLICT', 'The proposal has changed. Refresh before retrying.');
        }
    }

    private function assertLive(Proposal $proposal): void
    {
        if (! $proposal->isLive()) {
            throw new PrivacyException('INVALID_PROPOSAL_STATE', 'This proposal can no longer be changed.');
        }
    }

    private function assertNotExpired(Proposal $proposal): void
    {
        if ($proposal->valid_until->isPast()) {
            throw new PrivacyException('PROPOSAL_EXPIRED', 'This proposal has expired.');
        }
    }

    /** Live revalidation of case availability and Expert eligibility; the Expert's own engagement is not counted against them. */
    private function assertEligible(Expert $expert, CaseInvitation $inv, ?CaseRecord $case = null): void
    {
        $case ??= CaseRecord::findOrFail($inv->case_id);
        $scope = $case->serviceScopes()->with('catalogVersion.entry')->findOrFail($inv->scope_id);
        if ($case->status === CaseStatus::Cancelled || $scope->detached_at) {
            throw new PrivacyException('CASE_NOT_AVAILABLE', 'The case is no longer available.');
        }
        $current = $this->eligibility->revalidate($expert, $scope->catalogVersion, $inv->jurisdiction_code, $scope->delivery_mode, $case->currentIntake->payload['language'] ?? null, true, $inv->id);
        if (! $current['eligible']) {
            throw new PrivacyException('ELIGIBILITY_CHANGED', 'The expert is no longer eligible for this service.');
        }
    }

    /**
     * Validates money and builds the canonical, hashed version content. The total is always computed here.
     *
     * @return array{content: array<string, mixed>, milestones: list<array<string, mixed>>, hash: string, currency: string, total: int, minutes: int|null}
     */
    private function normalize(array $d): array
    {
        $currency = strtoupper((string) ($d['currency'] ?? ''));
        if (! Money::isAllowed($currency)) {
            throw new PrivacyException('CURRENCY_NOT_SUPPORTED', 'This currency is not supported.', 422);
        }
        $clean = fn (?string $v): ?string => $v === null ? null : (trim(strip_tags($v)) ?: null);
        $milestones = [];
        foreach (array_values($d['milestones']) as $i => $m) {
            if (! Money::isValidAmount($m['amountMinor'] ?? null)) {
                throw new PrivacyException('MONEY_INVALID', 'Amounts must be positive integers in minor units.', 422);
            }
            $due = ! empty($m['dueAt']) ? CarbonImmutable::parse($m['dueAt'])->utc() : null;
            $milestones[] = ['sequence' => $i + 1, 'title' => $clean($m['title']), 'description' => $clean($m['description'] ?? null), 'amountMinor' => $m['amountMinor'], 'dueAt' => $due?->format('Y-m-d\TH:i:s\Z')];
        }
        $total = Money::sum(array_column($milestones, 'amountMinor'));
        if ($total > (int) config('commerce.max_amount_minor')) {
            throw new PrivacyException('MONEY_INVALID', 'The total exceeds the maximum allowed amount.', 422);
        }
        $minutes = $d['consultationMinutes'] ?? null;
        $content = ['scopeSummary' => $clean($d['scopeSummary']), 'inputsRequired' => $clean($d['inputsRequired'] ?? null),
            'deliverables' => array_map(fn ($x) => ['title' => $clean($x['title']), 'description' => $clean($x['description'] ?? null)], array_values($d['deliverables'])),
            'exclusions' => array_map(fn ($x) => $clean($x), array_values($d['exclusions'] ?? [])),
            'clarificationAllowance' => (int) $d['clarificationAllowance'], 'reviewPeriodHours' => (int) $d['reviewPeriodHours'],
            'consultationMinutes' => $minutes === null ? null : (int) $minutes, 'currency' => $currency, 'milestones' => $milestones, 'totalAmountMinor' => $total];

        return ['content' => $content, 'milestones' => $milestones, 'hash' => hash('sha256', json_encode($content, JSON_THROW_ON_ERROR)), 'currency' => $currency, 'total' => $total, 'minutes' => $content['consultationMinutes']];
    }

    private function storeVersion(Proposal $proposal, int $no, array $n, CarbonImmutable|Carbon $validUntil): ProposalVersion
    {
        $version = new ProposalVersion;
        $version->forceFill(['proposal_id' => $proposal->id, 'version_no' => $no, 'content' => $n['content'], 'content_hash' => $n['hash'], 'currency' => $n['currency'],
            'total_amount_minor' => $n['total'], 'consultation_minutes' => $n['minutes'], 'valid_until' => $validUntil, 'created_at' => now()])->save();
        foreach ($n['milestones'] as $m) {
            $row = new ProposalMilestone;
            $row->forceFill(['proposal_version_id' => $version->id, 'sequence' => $m['sequence'], 'title' => $m['title'], 'description' => $m['description'], 'amount_minor' => $m['amountMinor'],
                'due_at' => $m['dueAt'] ? CarbonImmutable::parse($m['dueAt']) : null, 'created_at' => now()])->save();
        }

        return $version;
    }

    private function createAgreement(CaseRecord $case, Proposal $proposal, ProposalVersion $version): Agreement
    {
        $brief = CaseBrief::findOrFail($proposal->brief_id);
        $acceptedAt = now();
        $snapshot = ['schemaVersion' => 1, 'acceptedAt' => $acceptedAt->utc()->format('Y-m-d\TH:i:s\Z'), 'caseId' => $case->id, 'caseVersion' => $case->version, 'scopeId' => $proposal->scope_id,
            'briefId' => $brief->id, 'briefHash' => $brief->content_hash,
            'proposal' => ['id' => $proposal->id, 'versionNo' => $version->version_no, 'contentHash' => $version->content_hash, 'content' => $version->content],
            'milestones' => [], 'actors' => ['userId' => $proposal->user_id, 'expertId' => $proposal->expert_id],
            'funding' => ['state' => Agreement::AWAITING_PAYMENT, 'note' => 'Funding is not part of this agreement snapshot.']];
        $agreement = new Agreement;
        $agreement->forceFill(['case_id' => $case->id, 'scope_id' => $proposal->scope_id, 'invitation_id' => $proposal->invitation_id, 'proposal_id' => $proposal->id, 'proposal_version_id' => $version->id,
            'proposal_version_no' => $version->version_no, 'brief_id' => $brief->id, 'user_id' => $proposal->user_id, 'expert_id' => $proposal->expert_id, 'status' => Agreement::AWAITING_PAYMENT,
            'currency' => $version->currency, 'total_amount_minor' => $version->total_amount_minor, 'consultation_minutes' => $version->consultation_minutes, 'snapshot' => $snapshot,
            'snapshot_hash' => 'pending', 'accepted_at' => $acceptedAt, 'version' => 1])->save();
        foreach ($version->milestones as $m) {
            $row = new AgreementMilestone;
            $row->forceFill(['agreement_id' => $agreement->id, 'sequence' => $m->sequence, 'title' => $m->title, 'description' => $m->description, 'amount_minor' => $m->amount_minor,
                'currency' => $version->currency, 'due_at' => $m->due_at, 'created_at' => now()])->save();
            $snapshot['milestones'][] = ['id' => $row->id, 'sequence' => $m->sequence, 'title' => $m->title, 'description' => $m->description, 'amountMinor' => $m->amount_minor, 'dueAt' => $m->due_at?->utc()->format('Y-m-d\TH:i:s\Z')];
        }
        // The hash covers the complete snapshot including the stable milestone ids; sealing happens once, here.
        $agreement->forceFill(['snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))])->save();

        return $agreement;
    }

    private function event(Proposal $proposal, string $type, User|Expert|null $actor, ?int $versionNo, ?string $reason = null): void
    {
        $e = new ProposalEvent;
        $e->forceFill(['proposal_id' => $proposal->id, 'version_no' => $versionNo, 'type' => $type, 'actor_type' => match (true) {
            $actor instanceof User => 'user', $actor instanceof Expert => 'expert', default => 'system',
        }, 'actor_id' => $actor?->getKey(), 'reason_code' => $reason, 'created_at' => now()])->save();
    }
}
