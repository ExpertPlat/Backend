<?php

namespace App\Services\Payments;

use App\Models\Agreement;
use App\Models\Funding;
use App\Models\FundingAttempt;
use App\Services\Commerce\Bookings;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Funding-window expiry (DR-086). Callers hold the Agreement lock (and the Funding lock where a Funding exists).
 * Expiry preserves the immutable Agreement history, releases an unpaid booking, never charges later automatically, and requires an explicit retry.
 */
class FundingExpiry
{
    public function __construct(private Bookings $bookings, private Ledger $ledger, private FinanceNotifier $notify, private AuditWriter $audit, private StripeGateway $stripe) {}

    public function expireFunding(Funding $funding, Agreement $agreement): void
    {
        $sessions = [];
        foreach (FundingAttempt::where('funding_id', $funding->id)->whereIn('status', [FundingAttempt::OPEN, FundingAttempt::PROCESSING])->lockForUpdate()->get() as $attempt) {
            $attempt->forceFill(['status' => FundingAttempt::EXPIRED, 'active_key' => null])->save();
            $sessions[] = $attempt->stripe_checkout_session_id;
        }
        $funding->forceFill(['status' => Funding::EXPIRED, 'expired_at' => now(), 'active_key' => null, 'version' => $funding->version + 1])->save();
        $this->ledger->append($funding, 'funding_expired', 'funding_expired:'.$funding->id);
        $this->audit->write(null, 'funding.expired', 'funding', $funding->id, Funding::AWAITING_PAYMENT, Funding::EXPIRED, 'FUNDING_WINDOW_EXPIRED', ['agreementId' => $agreement->id]);
        $this->expireAgreement($agreement, $funding->id);
        $this->notify->user($funding->user_id, 'funding.expired', ['fundingId' => $funding->id, 'agreementId' => $agreement->id, 'caseId' => $agreement->case_id]);
        $this->notify->expert($funding->expert_id, 'funding.expired', ['agreementId' => $agreement->id]);
        // The provider session is closed after commit so a late payment cannot start; a failure here is harmless (a late payment is still never activated).
        DB::afterCommit(function () use ($sessions): void {
            foreach ($sessions as $id) {
                try {
                    $this->stripe->expireCheckoutSession($id);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }

    /** The Agreement is being cancelled while a Funding is still unfunded: close the Funding and its Checkout Sessions. Caller holds the Agreement lock. */
    public function cancelOpenFunding(Agreement $agreement): void
    {
        $funding = Funding::where('agreement_id', $agreement->id)->whereIn('status', Funding::UNFUNDED_OPEN)->lockForUpdate()->first();
        if ($funding === null) {
            return;
        }
        $sessions = [];
        foreach (FundingAttempt::where('funding_id', $funding->id)->whereIn('status', [FundingAttempt::OPEN, FundingAttempt::PROCESSING])->lockForUpdate()->get() as $attempt) {
            $attempt->forceFill(['status' => FundingAttempt::CANCELLED, 'active_key' => null])->save();
            $sessions[] = $attempt->stripe_checkout_session_id;
        }
        $funding->forceFill(['status' => Funding::CANCELLED, 'active_key' => null, 'version' => $funding->version + 1])->save();
        $this->ledger->append($funding, 'funding_cancelled', 'funding_cancelled:'.$funding->id);
        DB::afterCommit(function () use ($sessions): void {
            foreach ($sessions as $id) {
                try {
                    $this->stripe->expireCheckoutSession($id);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }

    /** Agreement-level expiry: also used for an Agreement that never had a Funding. Caller holds the Agreement lock. */
    public function expireAgreement(Agreement $agreement, ?int $fundingId = null): void
    {
        if ($agreement->status !== Agreement::AWAITING_PAYMENT) {
            return;
        }
        $agreement->forceFill(['status' => Agreement::FUNDING_EXPIRED, 'version' => $agreement->version + 1])->save();
        $this->bookings->cancelActiveForAgreement($agreement, null, 'FUNDING_EXPIRED');
        $this->audit->write(null, 'agreement.funding_expired', 'agreement', $agreement->id, Agreement::AWAITING_PAYMENT, Agreement::FUNDING_EXPIRED, 'FUNDING_WINDOW_EXPIRED', ['agreementId' => $agreement->id]);
        if ($fundingId === null) {
            $this->notify->user($agreement->user_id, 'funding.expired', ['agreementId' => $agreement->id, 'caseId' => $agreement->case_id]);
            $this->notify->expert($agreement->expert_id, 'funding.expired', ['agreementId' => $agreement->id]);
        }
    }
}
