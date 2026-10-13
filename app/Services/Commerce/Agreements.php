<?php

namespace App\Services\Commerce;

use App\Exceptions\PrivacyException;
use App\Models\Agreement;
use App\Models\CaseInvitation;
use App\Models\Expert;
use App\Models\Funding;
use App\Models\User;
use App\Notifications\Commerce\CommerceNotice;
use App\Services\Matching\Invitations;
use App\Services\Payments\FundingExpiry;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Facades\DB;

/** Agreement lifecycle after acceptance. The only transition in Sprint 4 is awaiting_payment -> cancelled. */
class Agreements
{
    public const CANCEL_REASONS = ['CHANGE_OF_PLANS', 'SCHEDULING_CONFLICT', 'NO_LONGER_NEEDED', 'EXPERT_UNAVAILABLE', 'OTHER'];

    public function __construct(private Bookings $bookings, private Invitations $invitations, private AuditWriter $audit) {}

    public function cancelByUser(User $user, int $caseId, int $id, array $d): Agreement
    {
        return DB::transaction(function () use ($user, $caseId, $id, $d): Agreement {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            // Lock order: case, expert, agreement, booking.
            Expert::query()->whereKey(Agreement::where('case_id', $case->id)->whereKey($id)->value('expert_id') ?? 0)->lockForUpdate()->first();
            $agreement = Agreement::where('case_id', $case->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCancellable($agreement, (int) $d['expectedVersion']);

            return $this->cancelRow($agreement, $user, $d['reasonCode'], true);
        });
    }

    public function cancelByExpert(Expert $expert, int $id, array $d): Agreement
    {
        return DB::transaction(function () use ($expert, $id, $d): Agreement {
            Expert::query()->whereKey($expert->id)->lockForUpdate()->firstOrFail();
            $agreement = Agreement::where('expert_id', $expert->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCancellable($agreement, (int) $d['expectedVersion']);

            return $this->cancelRow($agreement, $expert, $d['reasonCode'], true);
        });
    }

    /** Caller holds the locks (see lock order). Cancels the agreement, releases its booking and ends its invitation. */
    public function cancelRow(Agreement $agreement, User|Expert|null $actor, string $reason, bool $notify): Agreement
    {
        $previous = $agreement->status;
        app(FundingExpiry::class)->cancelOpenFunding($agreement);
        $agreement->forceFill(['status' => Agreement::CANCELLED, 'cancelled_at' => now(), 'cancel_reason_code' => $reason,
            'cancelled_by_type' => $actor instanceof Expert ? 'expert' : ($actor === null ? 'system' : 'user'), 'cancelled_by_id' => $actor?->getKey(), 'version' => $agreement->version + 1])->save();
        $this->bookings->cancelActiveForAgreement($agreement, $actor, 'AGREEMENT_CANCELLED');
        $inv = CaseInvitation::whereKey($agreement->invitation_id)->lockForUpdate()->first();
        if ($inv?->isOpen()) {
            if ($actor instanceof Expert) {
                $inv->forceFill(['decline_reason_code' => 'OTHER']);
                $this->invitations->close($inv, CaseInvitation::DECLINED, null, $actor, 'AGREEMENT_CANCELLED');
            } else {
                $this->invitations->close($inv, CaseInvitation::REVOKED, null, $actor, 'AGREEMENT_CANCELLED');
            }
        }
        $this->audit->write($actor, 'agreement.cancelled', 'agreement', $agreement->id, $previous, Agreement::CANCELLED, $reason, ['agreementId' => $agreement->id, 'proposalId' => $agreement->proposal_id]);
        if ($notify) {
            $refs = ['agreementId' => $agreement->id, 'caseId' => $agreement->case_id];
            if ($actor instanceof Expert) {
                User::find($agreement->user_id)?->notify(new CommerceNotice('agreement.cancelled', $refs));
            } else {
                $agreement->expert->notify(new CommerceNotice('agreement.cancelled', ['agreementId' => $agreement->id]));
            }
        }

        return $agreement;
    }

    private function assertCancellable(Agreement $agreement, int $expected): void
    {
        if ($agreement->version !== $expected) {
            throw new PrivacyException('VERSION_CONFLICT', 'The agreement has changed. Refresh before retrying.');
        }
        if (Funding::where('agreement_id', $agreement->id)->where('status', Funding::PROCESSING)->exists()) {
            throw new PrivacyException('PAYMENT_PROCESSING', 'A payment for this agreement is being processed. Wait for it to complete first.');
        }
        if (! in_array($agreement->status, [Agreement::AWAITING_PAYMENT, Agreement::FUNDING_EXPIRED], true)) {
            throw new PrivacyException('INVALID_AGREEMENT_STATE', 'Only an agreement that is awaiting payment can be cancelled.');
        }
    }
}
