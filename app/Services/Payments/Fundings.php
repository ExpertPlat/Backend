<?php

namespace App\Services\Payments;

use App\Exceptions\PrivacyException;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\Expert;
use App\Models\Funding;
use App\Models\FundingAttempt;
use App\Models\User;
use App\Services\Commerce\Bookings;
use App\Services\Privacy\AuditWriter;
use App\Support\Commission;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Funding creation, Checkout and expiry (DR-086, DR-087, DR-090). The payable amount and currency come only from the immutable
 * Agreement snapshot. Lock order: Case -> Expert -> Agreement -> Booking -> Funding.
 */
class Fundings
{
    public function __construct(private FundingDeadline $deadline, private FundingExpiry $expiry, private Ledger $ledger, private FinancialDocuments $documents,
        private ProviderSync $sync, private StripeGateway $stripe, private Bookings $bookings, private AuditWriter $audit) {}

    /**
     * `expired` is reported as data, not as an exception, because the expiry it caused must be committed (a thrown exception would roll the
     * idempotency transaction back and undo it).
     *
     * @return array{funding: ?Funding, created: bool, expired: bool}
     */
    public function open(User $user, int $caseId, int $agreementId): array
    {
        $windowExpired = false;
        $result = DB::transaction(function () use ($user, $caseId, $agreementId, &$windowExpired): ?array {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            [$agreement] = $this->lockAgreement($case->id, $agreementId);

            $active = Funding::where('agreement_id', $agreement->id)->whereIn('status', Funding::ACTIVE)->lockForUpdate()->first();
            if ($active !== null) {
                if ($active->status === Funding::FUNDED) {
                    throw new PrivacyException('AGREEMENT_ALREADY_FUNDED', 'This agreement is already funded.');
                }

                return [$active, false];
            }
            $basis = now()->toImmutable();
            switch ($agreement->status) {
                case Agreement::FUNDED:
                    throw new PrivacyException('AGREEMENT_ALREADY_FUNDED', 'This agreement is already funded.');
                case Agreement::FUNDING_EXPIRED: // explicit retry: revalidate before anything is reopened
                    $this->revalidateForRetry($agreement, $case);
                    $agreement->forceFill(['status' => Agreement::AWAITING_PAYMENT, 'version' => $agreement->version + 1])->save();
                    $this->audit->write($user, 'agreement.funding_reopened', 'agreement', $agreement->id, Agreement::FUNDING_EXPIRED, Agreement::AWAITING_PAYMENT, null, ['agreementId' => $agreement->id]);
                    break;
                case Agreement::AWAITING_PAYMENT:
                    $basis = CarbonImmutable::instance($agreement->accepted_at);
                    break;
                default:
                    throw new PrivacyException('AGREEMENT_NOT_FUNDABLE', 'This agreement cannot be funded in its current state.');
            }
            $this->assertFundableAmount($agreement);
            $due = $this->deadline->compute($agreement, $basis);
            if ($due['at']->lessThanOrEqualTo(now())) {
                $this->expiry->expireAgreement($agreement);
                $windowExpired = true;

                return null; // committed, then reported below (the expiry itself must persist)
            }
            $funding = $this->create($agreement, $due, $basis);
            $this->audit->write($user, 'funding.created', 'funding', $funding->id, null, Funding::AWAITING_PAYMENT, null, ['agreementId' => $agreement->id]);

            return [$funding, true];
        });
        if ($windowExpired || $result === null) {
            return ['funding' => null, 'created' => false, 'expired' => true];
        }

        return ['funding' => $result[0], 'created' => $result[1], 'expired' => false];
    }

    /** @return array{funding: ?Funding, attempt: ?FundingAttempt, reused: bool, expired: bool} */
    public function checkout(User $user, int $caseId, int $fundingId): array
    {
        $windowExpired = false;
        $result = DB::transaction(function () use ($user, $caseId, $fundingId, &$windowExpired): ?array {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            $head = Funding::where(['case_id' => $case->id, 'user_id' => $user->id])->findOrFail($fundingId);
            [$agreement] = $this->lockAgreement($case->id, $head->agreement_id);
            Booking::where('agreement_id', $agreement->id)->whereIn('status', Booking::ACTIVE)->lockForUpdate()->get();
            $funding = Funding::whereKey($head->id)->lockForUpdate()->firstOrFail();

            match ($funding->status) {
                Funding::FUNDED => throw new PrivacyException('AGREEMENT_ALREADY_FUNDED', 'This agreement is already funded.'),
                Funding::PROCESSING => throw new PrivacyException('PAYMENT_PROCESSING', 'A payment for this agreement is being processed.'),
                Funding::EXPIRED => throw new PrivacyException('FUNDING_WINDOW_EXPIRED', 'The funding window for this agreement has closed. Start a new funding request to continue.'),
                Funding::CANCELLED => throw new PrivacyException('INVALID_FUNDING_STATE', 'This funding was cancelled.'),
                default => null,
            };
            $due = $this->deadline->compute($agreement, $funding->deadline_basis_at);
            if ($due['at']->lessThanOrEqualTo(now())) {
                $this->expiry->expireFunding($funding, $agreement);
                $windowExpired = true;

                return null;
            }
            $funding->forceFill(['deadline_at' => $due['at'], 'deadline_rule' => $due['rule']])->save();
            $this->bookings->assertEligible(Expert::findOrFail($funding->expert_id), $agreement, $case);
            if (! $this->stripe->configured()) {
                throw new PrivacyException('PAYMENT_PROVIDER_UNAVAILABLE', 'The payment provider is not configured.', 503);
            }

            $live = FundingAttempt::where('funding_id', $funding->id)->whereIn('status', [FundingAttempt::OPEN, FundingAttempt::PROCESSING])->lockForUpdate()->get();
            foreach ($live as $attempt) {
                if ($attempt->status === FundingAttempt::OPEN && $attempt->expires_at->isFuture() && $attempt->checkout_url !== null) {
                    return ['funding' => $funding, 'attempt' => $attempt, 'reused' => true]; // safe reuse: same session, same amount
                }
                $attempt->forceFill(['status' => FundingAttempt::EXPIRED, 'active_key' => null])->save();
            }

            return ['funding' => $funding, 'attempt' => $this->newAttempt($funding, $agreement), 'reused' => false];
        });
        if ($windowExpired || $result === null) {
            return ['funding' => null, 'attempt' => null, 'reused' => false, 'expired' => true];
        }

        return $result + ['expired' => false];
    }

    /** User-triggered pull of the provider state for the current attempts (never trusts the client). */
    public function sync(User $user, int $caseId, int $fundingId): Funding
    {
        $funding = Funding::where(['case_id' => $caseId, 'user_id' => $user->id])->findOrFail($fundingId);
        if (in_array($funding->status, Funding::UNFUNDED_OPEN, true)) {
            $this->sync->syncFunding($funding->id);
        }

        return $funding->refresh();
    }

    /**
     * Recomputes the stored deadline after a booking change. Caller holds the Case/Agreement/Booking locks.
     * Never expires anything: the scheduler does that after syncing with the provider.
     */
    public function refreshDeadline(Agreement $agreement): void
    {
        $funding = Funding::where('agreement_id', $agreement->id)->whereIn('status', Funding::UNFUNDED_OPEN)->lockForUpdate()->first();
        if ($funding === null) {
            return;
        }
        $due = $this->deadline->compute($agreement, $funding->deadline_basis_at);
        if ($due['at']->getTimestamp() !== $funding->deadline_at->getTimestamp() || $due['rule'] !== $funding->deadline_rule) {
            $funding->forceFill(['deadline_at' => $due['at'], 'deadline_rule' => $due['rule']])->save();
        }
    }

    /** Scheduler: expire overdue Fundings (syncing with Stripe first) and overdue Agreements that never got a Funding. @return int number expired */
    public function expireDue(): int
    {
        $expired = 0;
        foreach (Funding::whereIn('status', Funding::UNFUNDED_OPEN)->orderBy('id')->pluck('id') as $id) {
            try {
                $expired += $this->expireOne($id);
            } catch (Throwable $e) {
                report($e); // one bad row must not stop the run
            }
        }
        $agreements = Agreement::where('status', Agreement::AWAITING_PAYMENT)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('fundings')->whereColumn('fundings.agreement_id', 'agreements.id')->whereIn('fundings.status', Funding::ACTIVE))
            ->orderBy('id')->pluck('id');
        foreach ($agreements as $id) {
            try {
                $expired += DB::transaction(function () use ($id): int {
                    $agreement = Agreement::whereKey($id)->lockForUpdate()->first();
                    if ($agreement === null || $agreement->status !== Agreement::AWAITING_PAYMENT || Funding::where('agreement_id', $id)->whereIn('status', Funding::ACTIVE)->exists()) {
                        return 0;
                    }
                    if ($this->deadline->compute($agreement, $agreement->accepted_at)['at']->greaterThan(now())) {
                        return 0;
                    }
                    $this->expiry->expireAgreement($agreement);

                    return 1;
                });
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $expired;
    }

    private function expireOne(int $id): int
    {
        $funding = Funding::findOrFail($id);
        $agreementHead = Agreement::findOrFail($funding->agreement_id);
        if ($this->deadline->compute($agreementHead, $funding->deadline_basis_at)['at']->greaterThan(now())) {
            return 0;
        }
        try { // a payment may have completed without us hearing (missed webhook): settle it first
            $this->sync->syncFunding($id);
        } catch (PrivacyException) {
            // provider unavailable: a payment made in time is still recovered later from provider state; a late one is never activated
        }

        return DB::transaction(function () use ($id): int {
            $head = Funding::findOrFail($id);
            $agreement = Agreement::whereKey($head->agreement_id)->lockForUpdate()->firstOrFail();
            Booking::where('agreement_id', $agreement->id)->whereIn('status', Booking::ACTIVE)->lockForUpdate()->get();
            $funding = Funding::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($funding->status, Funding::UNFUNDED_OPEN, true)) {
                return 0;
            }
            $due = $this->deadline->compute($agreement, $funding->deadline_basis_at);
            if ($due['at']->greaterThan(now())) {
                $funding->forceFill(['deadline_at' => $due['at'], 'deadline_rule' => $due['rule']])->save();

                return 0;
            }
            $this->expiry->expireFunding($funding, $agreement);

            return 1;
        });
    }

    // ------------------------------------------------------------------ internals

    /** @return array{0: Agreement} */
    private function lockAgreement(int $caseId, int $agreementId): array
    {
        $expertId = Agreement::where('case_id', $caseId)->whereKey($agreementId)->value('expert_id') ?? 0;
        Expert::query()->whereKey($expertId)->lockForUpdate()->first();

        return [Agreement::where('case_id', $caseId)->whereKey($agreementId)->lockForUpdate()->firstOrFail()];
    }

    private function assertFundableAmount(Agreement $agreement): void
    {
        if (! Money::isAllowed($agreement->currency)) {
            throw new PrivacyException('AGREEMENT_NOT_FUNDABLE', 'This agreement currency is not supported for payment.');
        }
        if (! Money::isProviderRepresentable($agreement->total_amount_minor, $agreement->currency)) {
            throw new PrivacyException('AMOUNT_NOT_PROVIDER_REPRESENTABLE', 'This amount cannot be charged in this currency. Ask the Expert for a revised proposal.');
        }
    }

    private function revalidateForRetry(Agreement $agreement, $case): void
    {
        // The commercial terms are the immutable Agreement snapshot (model-level guard); a sealed hash must exist.
        if (strlen((string) $agreement->snapshot_hash) !== 64) {
            throw new PrivacyException('AGREEMENT_NOT_FUNDABLE', 'The agreement terms could not be verified.');
        }
        $this->bookings->assertEligible(Expert::findOrFail($agreement->expert_id), $agreement, $case);
    }

    /** @param array{at: CarbonImmutable, rule: string} $due */
    private function create(Agreement $agreement, array $due, CarbonImmutable $basis): Funding
    {
        $bps = Commission::bps();
        $split = Commission::split($agreement->total_amount_minor, $bps);
        $funding = new Funding;
        $funding->forceFill(['agreement_id' => $agreement->id, 'user_id' => $agreement->user_id, 'expert_id' => $agreement->expert_id, 'case_id' => $agreement->case_id,
            'status' => Funding::AWAITING_PAYMENT, 'refund_status' => 'none', 'dispute_status' => 'none', 'gross_amount_minor' => $agreement->total_amount_minor, 'currency' => $agreement->currency,
            'original_amount_minor' => $agreement->total_amount_minor, 'discount_amount_minor' => 0, 'commission_bps' => $bps, 'platform_commission_minor' => $split['commission'],
            'expert_provisional_minor' => $split['expert'], 'retained_commission_minor' => $split['commission'], 'expert_remaining_minor' => $split['expert'],
            'deadline_at' => $due['at'], 'deadline_rule' => $due['rule'], 'deadline_basis_at' => $basis, 'active_key' => (string) $agreement->id, 'version' => 1]);
        try {
            $funding->save();
        } catch (UniqueConstraintViolationException) {
            throw new PrivacyException('AGREEMENT_NOT_FUNDABLE', 'A funding request already exists for this agreement.');
        }
        $this->documents->invoice($funding);
        $this->ledger->append($funding, 'funding_created', 'funding_created:'.$funding->id, ['amount' => $funding->gross_amount_minor]);

        return $funding;
    }

    private function newAttempt(Funding $funding, Agreement $agreement): FundingAttempt
    {
        $no = (int) FundingAttempt::where('funding_id', $funding->id)->max('attempt_no') + 1;
        $min = now()->addMinutes((int) config('payments.checkout_min_minutes'))->getTimestamp();
        $max = now()->addHours((int) config('payments.checkout_max_hours'))->getTimestamp();
        $expiresAt = max($min, min($max, $funding->deadline_at->getTimestamp()));
        $meta = ['funding_id' => (string) $funding->id, 'agreement_id' => (string) $funding->agreement_id];
        // `{caseId}` lets the return URL land on the Case's agreement page in the v7 frontend (/app/cases/{caseId}/agreement).
        $return = str_replace('{caseId}', (string) $funding->case_id, (string) config('payments.urls.checkout_success'));
        $cancel = str_replace('{caseId}', (string) $funding->case_id, (string) config('payments.urls.checkout_cancel'));
        $session = $this->stripe->createCheckoutSession([
            'mode' => 'payment',
            'client_reference_id' => 'funding_'.$funding->id,
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower($funding->currency), 'unit_amount' => $funding->gross_amount_minor,
                'product_data' => ['name' => 'SolveIt service funding']]]],
            'metadata' => $meta + ['attempt_no' => (string) $no],
            'payment_intent_data' => ['metadata' => $meta + ['attempt_no' => (string) $no], 'transfer_group' => 'agreement_'.$agreement->id, 'description' => 'SolveIt service funding'],
            'expires_at' => $expiresAt,
            'success_url' => $this->withQuery($return, ['funding' => $funding->id, 'session_id' => '{CHECKOUT_SESSION_ID}']),
            'cancel_url' => $this->withQuery($cancel, ['funding' => $funding->id]),
        ], 'funding_'.$funding->id.'_attempt_'.$no);

        $attempt = new FundingAttempt;
        $attempt->forceFill(['funding_id' => $funding->id, 'attempt_no' => $no, 'status' => FundingAttempt::OPEN, 'stripe_checkout_session_id' => $session['id'],
            'checkout_url' => $session['url'] ?? null, 'expires_at' => CarbonImmutable::createFromTimestampUTC((int) ($session['expires_at'] ?? $expiresAt)), 'active_key' => (string) $funding->id]);
        try {
            $attempt->save();
        } catch (UniqueConstraintViolationException) {
            throw new PrivacyException('PAYMENT_PROCESSING', 'A checkout is already being prepared for this funding.');
        }
        if ($funding->status === Funding::FAILED) {
            $funding->forceFill(['status' => Funding::AWAITING_PAYMENT, 'version' => $funding->version + 1])->save();
        }
        $this->ledger->append($funding, 'checkout_created', 'checkout_created:'.$attempt->id, ['provider_ref' => $attempt->stripe_checkout_session_id]);

        return $attempt;
    }

    /** @param array<string, int|string> $query */
    private function withQuery(string $url, array $query): string
    {
        if ($url === '') {
            throw new PrivacyException('PAYMENT_PROVIDER_UNAVAILABLE', 'The checkout return URLs are not configured.', 503);
        }
        // `{CHECKOUT_SESSION_ID}` is a Stripe placeholder and must stay unescaped.
        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[] = rawurlencode($k).'='.($v === '{CHECKOUT_SESSION_ID}' ? $v : rawurlencode((string) $v));
        }

        return $url.(str_contains($url, '?') ? '&' : '?').implode('&', $pairs);
    }
}
