<?php

namespace App\Services\Payments;

use App\Models\Expert;
use App\Models\Funding;
use App\Models\FundingAttempt;
use App\Models\FundingRefund;
use App\Models\StripeAccount;
use Throwable;

/**
 * Webhooks are primary; this catches what they missed (DR-090). Every write goes through the same idempotent transition path
 * ({@see ProviderSync}, {@see Refunds}, {@see Disputes}) and the ledger dedupe keys, so running it twice, or while a webhook arrives, never doubles an effect.
 */
class PaymentReconciler
{
    public function __construct(private ProviderSync $sync, private Refunds $refunds, private Disputes $disputes, private ConnectAccounts $accounts, private StripeGateway $stripe,
        private Ledger $ledger, private Reconciliation $reconciliation) {}

    /** Scheduler entry. @return array{fundings: int, refunds: int, accounts: int} */
    public function run(): array
    {
        $cut = now()->subMinutes((int) config('payments.reconcile_stale_minutes'));
        $fundings = 0;
        foreach (Funding::whereIn('status', [Funding::AWAITING_PAYMENT, Funding::REQUIRES_ACTION, Funding::PROCESSING, Funding::FAILED])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('funding_attempts')->whereColumn('funding_attempts.funding_id', 'fundings.id')->whereIn('funding_attempts.status', [FundingAttempt::OPEN, FundingAttempt::PROCESSING]))
            ->where('updated_at', '<=', $cut)->orderBy('id')->pluck('id') as $id) {
            try {
                $this->reconcileFunding($id);
                $fundings++;
            } catch (Throwable $e) {
                report($e);
            }
        }
        $refunds = $this->refunds->reconcileStale();
        $accounts = 0;
        foreach (StripeAccount::where(fn ($q) => $q->where('payouts_enabled', false)->orWhereNotNull('disabled_reason'))->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', now()->subHours(6)))->pluck('expert_id') as $expertId) {
            try {
                $expert = Expert::find($expertId);
                if ($expert !== null) {
                    $this->accounts->sync($expert);
                    $accounts++;
                }
            } catch (Throwable $e) {
                $this->reconciliation->raise(null, 'ACCOUNT_SYNC_FAILED', (string) $expertId, ['expertId' => (int) $expertId], false);
                report($e);
            }
        }

        return ['fundings' => $fundings, 'refunds' => $refunds, 'accounts' => $accounts];
    }

    /** Reconciles one Funding against Stripe. Safe to call repeatedly. @return array{status: string, changed: bool} */
    public function reconcileFunding(int $fundingId, bool $deep = false): array
    {
        $before = Funding::findOrFail($fundingId)->status;
        $this->sync->syncFunding($fundingId);
        $funding = Funding::findOrFail($fundingId);
        if ($before !== Funding::FUNDED && $funding->status === Funding::FUNDED) {
            $this->ledger->append($funding, 'reconciliation_corrected', 'reconciliation_corrected:paid:'.$funding->id, ['provider_ref' => $funding->stripe_payment_intent_id]);
            $this->reconciliation->raise($funding, 'PROVIDER_PAID_INTERNAL_PENDING', 'funding', ['was' => $before], false);
            $this->reconciliation->resolve($funding, 'PROVIDER_PAID_INTERNAL_PENDING', 'FUNDED_FROM_PROVIDER_STATE');
        }
        if ($deep && $funding->status === Funding::FUNDED && $funding->stripe_payment_intent_id !== null) {
            $this->verifyFunded($funding);
        }

        return ['status' => $funding->refresh()->status, 'changed' => $before !== $funding->status];
    }

    /** Compares our funded state with the provider: payment, refunds and disputes. Mismatches become Admin issues; nothing is rewritten silently. */
    private function verifyFunded(Funding $funding): void
    {
        $pi = $this->stripe->retrievePaymentIntent($funding->stripe_payment_intent_id);
        if (($pi['status'] ?? '') !== 'succeeded') {
            $this->reconciliation->raise($funding, 'INTERNAL_FUNDED_PROVIDER_UNPAID', (string) $funding->stripe_payment_intent_id, ['providerStatus' => (string) ($pi['status'] ?? '')]);

            return;
        }
        $charge = is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : [];
        $providerRefunded = (int) ($charge['amount_refunded'] ?? 0);
        $known = (int) FundingRefund::where('funding_id', $funding->id)->where('kind', 'standard')->whereIn('status', [FundingRefund::SUCCEEDED, FundingRefund::PROCESSING, FundingRefund::APPROVED])->sum('amount_minor');
        if ($providerRefunded !== $known) {
            $this->reconciliation->raise($funding, 'REFUND_MISMATCH', 'charge', ['providerRefundedMinor' => $providerRefunded, 'knownMinor' => $known]);
        }
        if (! empty($charge['disputed']) && $funding->dispute_status === 'none') {
            $this->reconciliation->raise($funding, 'DISPUTE_MISMATCH', 'deep', ['fundingId' => $funding->id]);
        }
        $this->refunds->syncForCharge($charge + ['payment_intent' => $funding->stripe_payment_intent_id]);
    }
}
