<?php

namespace App\Services\Payments;

use App\Models\Agreement;
use App\Models\Funding;
use App\Models\FundingAttempt;
use App\Services\Privacy\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The single transition path from trusted Stripe state to Funding state (DR-090). Webhooks, the User `sync` endpoint and reconciliation all
 * come through here: the authoritative objects are fetched from Stripe, never taken from the client or from a webhook payload.
 *
 * Lock order: Agreement -> Funding -> Attempt. A `funded` Funding is never reverted by stale or reordered events.
 */
class ProviderSync
{
    public function __construct(private StripeGateway $stripe, private FundingExpiry $expiry, private Ledger $ledger, private FinancialDocuments $documents,
        private Reconciliation $reconciliation, private Refunds $refunds, private FinanceNotifier $notify, private AuditWriter $audit) {}

    /** Pulls the Checkout Session + PaymentIntent for one attempt and applies them. */
    public function syncAttempt(int $attemptId): void
    {
        $attempt = FundingAttempt::findOrFail($attemptId);
        $session = $this->stripe->retrieveCheckoutSession($attempt->stripe_checkout_session_id);
        $piRef = $session['payment_intent'] ?? null;
        $piId = is_array($piRef) ? ($piRef['id'] ?? null) : $piRef;
        $pi = is_string($piId) && $piId !== '' ? $this->stripe->retrievePaymentIntent($piId) : null;
        $this->apply($attemptId, $this->normalize($session, $pi), $session, $pi);
    }

    /** Syncs every live (or recently settled but unconfirmed) attempt of a Funding. */
    public function syncFunding(int $fundingId): void
    {
        foreach (FundingAttempt::where('funding_id', $fundingId)->whereIn('status', [FundingAttempt::OPEN, FundingAttempt::PROCESSING])->orderBy('id')->pluck('id') as $id) {
            $this->syncAttempt($id);
        }
    }

    /** Resolves an attempt from a webhook PaymentIntent reference (id, else the opaque metadata we set at creation). */
    public function attemptForPaymentIntent(array $pi): ?int
    {
        $id = FundingAttempt::where('stripe_payment_intent_id', $pi['id'] ?? '')->value('id');
        if ($id === null && isset($pi['metadata']['attempt_no'], $pi['metadata']['funding_id'])) {
            $id = FundingAttempt::where(['attempt_no' => (int) $pi['metadata']['attempt_no'], 'funding_id' => (int) $pi['metadata']['funding_id']])->value('id');
        }

        return $id === null ? null : (int) $id;
    }

    /** @return array{state: string, paidAt: ?CarbonImmutable, feeMinor: ?int, receiptUrl: ?string, piId: ?string, amount: ?int, currency: ?string} */
    private function normalize(array $session, ?array $pi): array
    {
        $out = ['state' => 'open', 'paidAt' => null, 'feeMinor' => null, 'receiptUrl' => null, 'piId' => $pi['id'] ?? null, 'amount' => $pi['amount'] ?? null, 'currency' => $pi['currency'] ?? null];
        if ($pi !== null) {
            $out['state'] = match ($pi['status'] ?? '') {
                'succeeded' => 'paid',
                'processing' => 'processing',
                'requires_action' => 'requires_action',
                'canceled' => 'expired',
                'requires_payment_method' => ! empty($pi['last_payment_error']) ? 'failed' : 'open',
                default => 'processing',
            };
            $charge = is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : null;
            if ($out['state'] === 'paid') {
                $out['paidAt'] = CarbonImmutable::createFromTimestampUTC((int) ($charge['created'] ?? $pi['created'] ?? now()->getTimestamp()));
                $out['receiptUrl'] = is_string($charge['receipt_url'] ?? null) ? $charge['receipt_url'] : null;
                $bt = is_array($charge['balance_transaction'] ?? null) ? $charge['balance_transaction'] : null;
                if ($bt !== null && isset($bt['fee']) && strtolower((string) ($bt['currency'] ?? '')) === strtolower((string) $out['currency'])) {
                    $out['feeMinor'] = (int) $bt['fee']; // only when it is in the charge currency; otherwise it is not stored
                }
            }

            return $out;
        }
        if (($session['status'] ?? '') === 'expired') {
            $out['state'] = 'expired';
        } elseif (($session['status'] ?? '') === 'complete' && ($session['payment_status'] ?? '') === 'paid') {
            $out['state'] = 'paid';
            $out['paidAt'] = now()->toImmutable();
            $out['amount'] = $session['amount_total'] ?? null;
            $out['currency'] = $session['currency'] ?? null;
        }

        return $out;
    }

    /** @param array{state: string, paidAt: ?CarbonImmutable, feeMinor: ?int, receiptUrl: ?string, piId: ?string, amount: ?int, currency: ?string} $n */
    private function apply(int $attemptId, array $n, array $session, ?array $pi): void
    {
        DB::transaction(function () use ($attemptId, $n, $pi): void {
            $head = FundingAttempt::findOrFail($attemptId);
            $fundingHead = Funding::findOrFail($head->funding_id);
            $agreement = Agreement::whereKey($fundingHead->agreement_id)->lockForUpdate()->firstOrFail();
            $funding = Funding::whereKey($fundingHead->id)->lockForUpdate()->firstOrFail();
            $attempt = FundingAttempt::whereKey($attemptId)->lockForUpdate()->firstOrFail();

            if ($n['piId'] !== null && $attempt->stripe_payment_intent_id === null) {
                $attempt->forceFill(['stripe_payment_intent_id' => $n['piId']])->save();
            }
            if ($pi !== null && isset($pi['metadata']['funding_id']) && (int) $pi['metadata']['funding_id'] !== $funding->id) {
                $this->reconciliation->raise($funding, 'AMOUNT_MISMATCH', 'metadata:'.$attempt->id, ['attemptId' => $attempt->id, 'reason' => 'FUNDING_REFERENCE_MISMATCH']);

                return;
            }

            match ($n['state']) {
                'paid' => $this->paid($agreement, $funding, $attempt, $n),
                'processing', 'requires_action' => $this->inFlight($funding, $attempt, $n['state']),
                'failed' => $this->failed($funding, $attempt, $pi),
                'expired' => $this->closed($funding, $attempt, FundingAttempt::EXPIRED),
                default => null,
            };
        });
    }

    private function paid(Agreement $agreement, Funding $funding, FundingAttempt $attempt, array $n): void
    {
        if ($attempt->status === FundingAttempt::SUCCEEDED) {
            return;
        }
        $piId = (string) $n['piId'];
        $paidAt = $n['paidAt'] ?? now()->toImmutable();

        // Amount/currency come from the immutable Funding snapshot; whatever Stripe actually collected must match it exactly.
        if ($n['amount'] !== $funding->gross_amount_minor || strtolower((string) $n['currency']) !== strtolower($funding->currency)) {
            $this->reconciliation->raise($funding, 'AMOUNT_MISMATCH', $piId, ['attemptId' => $attempt->id]);

            return;
        }

        if ($funding->status === Funding::FUNDED) {
            $attempt->forceFill(['status' => FundingAttempt::SUCCEEDED, 'active_key' => null, 'provider_paid_at' => $paidAt])->save();
            if ($funding->stripe_payment_intent_id !== $piId) {
                $this->ledger->append($funding, 'duplicate_payment_detected', 'duplicate:'.$piId, ['amount' => $n['amount'], 'provider_ref' => $piId]);
                $this->reconciliation->raise($funding, 'DUPLICATE_PAYMENT', $piId, ['attemptId' => $attempt->id]);
                $this->refunds->openDuplicate($funding, $piId, (int) $n['amount']);
            }

            return;
        }

        $late = $paidAt->greaterThan($funding->deadline_at);
        if (in_array($funding->status, Funding::UNFUNDED_OPEN, true) && $late) {
            $this->expiry->expireFunding($funding, $agreement);
            $funding->refresh();
        }
        $recoverable = $funding->status === Funding::EXPIRED && ! $late && $agreement->status === Agreement::FUNDING_EXPIRED;
        if (in_array($funding->status, Funding::UNFUNDED_OPEN, true) || $recoverable) {
            $this->fund($agreement, $funding, $attempt, $n, $paidAt, $piId);

            return;
        }

        // The window had already closed: never activate silently. The money is held for an Admin decision.
        $attempt->forceFill(['status' => FundingAttempt::SUCCEEDED, 'active_key' => null, 'provider_paid_at' => $paidAt])->save();
        $this->ledger->append($funding, 'late_payment_received', 'late:'.$piId, ['amount' => $n['amount'], 'provider_ref' => $piId]);
        $this->reconciliation->raise($funding, 'LATE_PAYMENT', $piId, ['attemptId' => $attempt->id]);
    }

    private function fund(Agreement $agreement, Funding $funding, FundingAttempt $attempt, array $n, CarbonImmutable $paidAt, string $piId): void
    {
        $from = $funding->status;
        $attempt->forceFill(['status' => FundingAttempt::SUCCEEDED, 'active_key' => null, 'provider_paid_at' => $paidAt])->save();
        $sessions = [];
        foreach (FundingAttempt::where('funding_id', $funding->id)->where('id', '!=', $attempt->id)->whereIn('status', [FundingAttempt::OPEN, FundingAttempt::PROCESSING])->lockForUpdate()->get() as $other) {
            $other->forceFill(['status' => FundingAttempt::CANCELLED, 'active_key' => null])->save();
            $sessions[] = $other->stripe_checkout_session_id;
        }
        $funding->forceFill(['status' => Funding::FUNDED, 'funded_at' => $paidAt, 'stripe_payment_intent_id' => $piId, 'provider_fee_minor' => $n['feeMinor'],
            'active_key' => (string) $funding->agreement_id, 'version' => $funding->version + 1])->save();
        $agreement->forceFill(['status' => Agreement::FUNDED, 'version' => $agreement->version + 1])->save();
        $this->documents->receipt($funding, $n['receiptUrl']);
        $this->ledger->append($funding, 'payment_confirmed', 'payment_confirmed:'.$funding->id, ['amount' => $funding->gross_amount_minor, 'provider_ref' => $piId, 'occurred_at' => $paidAt]);
        $this->audit->write(null, 'funding.confirmed', 'funding', $funding->id, $from, Funding::FUNDED, null, ['agreementId' => $agreement->id]);
        $this->notify->user($funding->user_id, 'funding.confirmed', ['fundingId' => $funding->id, 'agreementId' => $agreement->id, 'caseId' => $agreement->case_id]);
        $this->notify->expert($funding->expert_id, 'funding.confirmed', ['agreementId' => $agreement->id]);
        $this->reconciliation->resolve($funding, 'PROVIDER_PAID_INTERNAL_PENDING', 'FUNDED_FROM_PROVIDER_STATE');
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

    private function inFlight(Funding $funding, FundingAttempt $attempt, string $state): void
    {
        if (! in_array($funding->status, Funding::UNFUNDED_OPEN, true) || in_array($attempt->status, [FundingAttempt::SUCCEEDED, FundingAttempt::FAILED, FundingAttempt::EXPIRED, FundingAttempt::CANCELLED], true)) {
            return; // stale/reordered event: never move a settled object backwards
        }
        $attempt->forceFill(['status' => $state === 'processing' ? FundingAttempt::PROCESSING : FundingAttempt::OPEN])->save();
        $next = $state === 'processing' ? Funding::PROCESSING : Funding::REQUIRES_ACTION;
        if ($funding->status !== $next) {
            $funding->forceFill(['status' => $next, 'version' => $funding->version + 1])->save();
            if ($state === 'processing') {
                $this->ledger->append($funding, 'payment_processing', 'payment_processing:'.$attempt->id, ['provider_ref' => $attempt->stripe_payment_intent_id]);
            } else {
                $this->notify->user($funding->user_id, 'funding.requires_action', ['fundingId' => $funding->id, 'agreementId' => $funding->agreement_id]);
            }
        }
    }

    private function failed(Funding $funding, FundingAttempt $attempt, ?array $pi): void
    {
        if (in_array($attempt->status, [FundingAttempt::SUCCEEDED, FundingAttempt::FAILED], true)) {
            return;
        }
        $code = is_string($pi['last_payment_error']['code'] ?? null) ? substr($pi['last_payment_error']['code'], 0, 60) : 'PAYMENT_FAILED';
        $attempt->forceFill(['status' => FundingAttempt::FAILED, 'failure_code' => $code, 'active_key' => null])->save();
        if (in_array($funding->status, Funding::UNFUNDED_OPEN, true)) {
            $funding->forceFill(['status' => Funding::FAILED, 'version' => $funding->version + 1])->save();
            $this->ledger->append($funding, 'payment_failed', 'payment_failed:'.$attempt->id, ['provider_ref' => $attempt->stripe_payment_intent_id]);
            $this->notify->user($funding->user_id, 'funding.failed', ['fundingId' => $funding->id, 'agreementId' => $funding->agreement_id]);
        }
    }

    private function closed(Funding $funding, FundingAttempt $attempt, string $status): void
    {
        if (! $attempt->isLive()) {
            return;
        }
        $attempt->forceFill(['status' => $status, 'active_key' => null])->save();
        $live = FundingAttempt::where('funding_id', $funding->id)->whereIn('status', [FundingAttempt::OPEN, FundingAttempt::PROCESSING])->exists();
        if (! $live && in_array($funding->status, [Funding::REQUIRES_ACTION, Funding::PROCESSING], true)) {
            $funding->forceFill(['status' => Funding::AWAITING_PAYMENT, 'version' => $funding->version + 1])->save();
        }
    }
}
