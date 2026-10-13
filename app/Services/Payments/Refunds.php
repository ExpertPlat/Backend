<?php

namespace App\Services\Payments;

use App\Exceptions\PrivacyException;
use App\Jobs\Payments\ProcessRefund;
use App\Models\Admin;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\Expert;
use App\Models\Funding;
use App\Models\FundingAttempt;
use App\Models\FundingRefund;
use App\Models\User;
use App\Services\Commerce\Agreements;
use App\Services\Privacy\AuditWriter;
use App\Support\Commission;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Refund policy and execution (DR-089). Decisions are made under locks; Stripe is called only by the queued {@see ProcessRefund}
 * with a deterministic idempotency key, and money state follows Stripe confirmation (webhook/sync), never the request.
 * Lock order: Case -> Expert -> Agreement -> Booking -> Funding -> Refund.
 */
class Refunds
{
    public const USER_REASONS = ['USER_CANCELLATION', 'EXPERT_CANCELLATION'];

    public const ADMIN_REASONS = ['TECHNICAL_FAILURE', 'ADMIN_DECISION', 'LATE_PAYMENT', 'DUPLICATE_CHARGE'];

    public const CHOICES = ['full_refund', 'free_reschedule', 'request_cancellation_review'];

    public function __construct(private StripeGateway $stripe, private Ledger $ledger, private FinanceNotifier $notify, private AuditWriter $audit,
        private Agreements $agreements, private Reconciliation $reconciliation) {}

    /** @param array{reasonCode: string, choice: string, note?: string|null} $d @return array{outcome: string, refund: ?FundingRefund, bookingId: ?int} */
    public function requestByUser(User $user, int $caseId, int $fundingId, array $d): array
    {
        $refundId = null;
        $result = DB::transaction(function () use ($user, $caseId, $fundingId, $d, &$refundId): array {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            $head = Funding::where(['case_id' => $case->id, 'user_id' => $user->id])->findOrFail($fundingId);
            Expert::query()->whereKey($head->expert_id)->lockForUpdate()->first();
            $agreement = Agreement::whereKey($head->agreement_id)->lockForUpdate()->firstOrFail();
            $bookings = Booking::where('agreement_id', $agreement->id)->orderByDesc('id')->lockForUpdate()->get();
            $funding = Funding::whereKey($head->id)->lockForUpdate()->firstOrFail();
            $this->assertRefundable($funding);

            $active = $bookings->first(fn (Booking $b): bool => $b->status === Booking::CONFIRMED);
            $reason = $d['reasonCode'];
            $choice = $d['choice'];
            $noticeSeconds = (int) config('commerce.cancellation.user_full_refund_or_free_reschedule_notice_hours') * 3600;

            if ($reason === 'EXPERT_CANCELLATION') {
                $latest = $bookings->first();
                if ($latest === null || $latest->status !== Booking::CANCELLED || $latest->cancelled_by_type !== 'expert') {
                    throw new PrivacyException('REFUND_REASON_NOT_VERIFIED', 'No Expert cancellation was recorded for this service.');
                }
                if ($choice === 'request_cancellation_review') {
                    throw new PrivacyException('REFUND_NOT_ALLOWED', 'Choose a full refund or a free reschedule.');
                }
                if ($choice === 'free_reschedule') {
                    $this->audit->write($user, 'refund.free_reschedule_chosen', 'funding', $funding->id, null, null, 'EXPERT_CANCELLATION', ['agreementId' => $agreement->id]);

                    return ['outcome' => 'free_reschedule', 'refund' => null, 'bookingId' => $latest->id];
                }

                return ['outcome' => 'refund_approved', 'refund' => $this->approve($funding, $agreement, $reason, 'user', $user->id, $funding->remainingRefundable(), null, null), 'bookingId' => $latest->id];
            }

            // USER_CANCELLATION
            if ($active !== null && $active->start_at->isPast()) {
                throw new PrivacyException('REFUND_NOT_ALLOWED', 'The consultation has already started.');
            }
            if ($active === null) { // no scheduled consultation: no approved rule yet -> fail closed to manual review (documented decision pending)
                if ($choice === 'request_cancellation_review') {
                    return ['outcome' => 'refund_review_requested', 'refund' => $this->openReview($funding, $agreement, $reason, $user, $d['note'] ?? null), 'bookingId' => null];
                }
                throw new PrivacyException($choice === 'full_refund' ? 'REFUND_REVIEW_REQUIRED' : 'REFUND_NOT_ALLOWED', $choice === 'full_refund'
                    ? 'This cancellation needs a manual review. Submit a cancellation review request.' : 'There is no scheduled consultation to reschedule.');
            }
            $early = $active->start_at->timestamp - now()->timestamp >= $noticeSeconds;

            return match ($choice) {
                'full_refund' => $early
                    ? ['outcome' => 'refund_approved', 'refund' => $this->approve($funding, $agreement, $reason, 'user', $user->id, $funding->remainingRefundable(), null, null), 'bookingId' => $active->id]
                    : throw new PrivacyException('REFUND_REVIEW_REQUIRED', 'Cancelling inside the notice window needs a manual review. You can use your free reschedule or request a review.'),
                'free_reschedule' => $this->grantReschedule($user, $funding, $active, $early),
                default => $early
                    ? throw new PrivacyException('REFUND_NOT_ALLOWED', 'You can cancel for a full refund or reschedule for free.')
                    : ['outcome' => 'refund_review_requested', 'refund' => $this->openReview($funding, $agreement, $reason, $user, $d['note'] ?? null), 'bookingId' => $active->id],
            };
        });
        if ($result['refund'] !== null && $result['refund']->status === FundingRefund::APPROVED) {
            ProcessRefund::dispatch($result['refund']->id);
        }

        return $result;
    }

    /** @param array{decision: string, amountMinor?: int|null, reason: string, expectedVersion: int} $d */
    public function decide(Admin $admin, int $refundId, array $d): FundingRefund
    {
        $refund = DB::transaction(function () use ($admin, $refundId, $d): FundingRefund {
            $head = FundingRefund::findOrFail($refundId);
            $headFunding = Funding::findOrFail($head->funding_id);
            $agreement = Agreement::whereKey($headFunding->agreement_id)->lockForUpdate()->firstOrFail();
            $funding = Funding::whereKey($headFunding->id)->lockForUpdate()->firstOrFail();
            $refund = FundingRefund::whereKey($refundId)->lockForUpdate()->firstOrFail();
            if ($refund->version !== (int) $d['expectedVersion']) {
                throw new PrivacyException('VERSION_CONFLICT', 'The refund request has changed. Refresh before retrying.');
            }
            if ($refund->status !== FundingRefund::PENDING_REVIEW) {
                throw new PrivacyException('INVALID_REFUND_STATE', 'This refund request has already been decided.');
            }
            if ($d['decision'] === 'reject') {
                $refund->forceFill(['status' => FundingRefund::REJECTED, 'active_key' => null, 'decision' => 'reject', 'decision_reason' => $d['reason'], 'reviewed_by_admin_id' => $admin->id,
                    'decided_at' => now(), 'version' => $refund->version + 1])->save();
                $this->refreshRefundStatus($funding);
                $this->ledger->append($funding, 'refund_rejected', 'refund_rejected:'.$refund->id, ['actor_type' => 'admin', 'actor_id' => $admin->id]);
                $this->audit->write($admin, 'refund.rejected', 'funding_refund', $refund->id, FundingRefund::PENDING_REVIEW, FundingRefund::REJECTED, null, ['fundingId' => $funding->id]);
                $this->notify->user($refund->user_id, 'refund.rejected', ['fundingId' => $funding->id, 'refundId' => $refund->id]);

                return $refund;
            }
            $this->assertRefundable($funding, $refund->id);
            $remaining = $funding->remainingRefundable();
            $amount = $d['decision'] === 'approve_full' ? $remaining : (int) ($d['amountMinor'] ?? 0);
            $this->assertAmount($funding, $amount);
            $approved = $this->approve($funding, $agreement, $refund->reason_code, 'admin', $admin->id, $amount, $d['reason'], $refund);
            $approved->forceFill(['reviewed_by_admin_id' => $admin->id, 'decision' => $d['decision'], 'decided_at' => now()])->save();

            return $approved;
        });
        if ($refund->status === FundingRefund::APPROVED) {
            ProcessRefund::dispatch($refund->id);
        }

        return $refund;
    }

    /** Admin-initiated refund (technical failure, Admin decision, late or duplicate payment). @param array{reasonCode: string, amountMinor?: int|null, attemptId?: int|null, reason: string} $d */
    public function createByAdmin(Admin $admin, int $fundingId, array $d): FundingRefund
    {
        $refund = DB::transaction(function () use ($admin, $fundingId, $d): FundingRefund {
            $head = Funding::findOrFail($fundingId);
            $agreement = Agreement::whereKey($head->agreement_id)->lockForUpdate()->firstOrFail();
            $funding = Funding::whereKey($head->id)->lockForUpdate()->firstOrFail();
            if (in_array($d['reasonCode'], ['LATE_PAYMENT', 'DUPLICATE_CHARGE'], true)) {
                return $this->approveStray($admin, $funding, $d);
            }
            $this->assertRefundable($funding);
            $amount = isset($d['amountMinor']) ? (int) $d['amountMinor'] : $funding->remainingRefundable();
            $this->assertAmount($funding, $amount);

            return $this->approve($funding, $agreement, $d['reasonCode'], 'admin', $admin->id, $amount, $d['reason'], null);
        });
        ProcessRefund::dispatch($refund->id);

        return $refund;
    }

    /** Called under the Agreement + Funding locks of {@see ProviderSync} once Stripe confirmed a second successful payment. */
    public function openDuplicate(Funding $funding, string $paymentIntentId, int $amount): FundingRefund
    {
        $existing = FundingRefund::where('active_key', 'd'.$paymentIntentId)->orWhere(fn ($q) => $q->where('payment_intent_id', $paymentIntentId)->where('kind', 'duplicate'))->first();
        if ($existing !== null) {
            return $existing;
        }
        $refund = new FundingRefund;
        $refund->forceFill(['funding_id' => $funding->id, 'user_id' => $funding->user_id, 'initiated_by_type' => 'system', 'reason_code' => 'DUPLICATE_CHARGE', 'kind' => 'duplicate',
            'payment_intent_id' => $paymentIntentId, 'amount_minor' => $amount, 'currency' => $funding->currency, 'status' => FundingRefund::APPROVED, 'active_key' => 'd'.$paymentIntentId, 'version' => 1]);
        try {
            $refund->save();
        } catch (UniqueConstraintViolationException) {
            return FundingRefund::where('payment_intent_id', $paymentIntentId)->where('kind', 'duplicate')->firstOrFail();
        }
        $this->ledger->append($funding, 'refund_approved', 'refund_approved:'.$refund->id, ['amount' => $amount, 'provider_ref' => $paymentIntentId]);
        ProcessRefund::dispatch($refund->id);

        return $refund;
    }

    /** Queue entry point. Never throws for provider outages: a stale approved refund is picked up again by reconciliation. */
    public function submit(int $refundId): void
    {
        $refund = FundingRefund::find($refundId);
        if ($refund === null || $refund->status !== FundingRefund::APPROVED) {
            return;
        }
        $funding = Funding::findOrFail($refund->funding_id);
        $intent = $refund->payment_intent_id ?? $funding->stripe_payment_intent_id;
        try {
            $stripeRefund = $this->stripe->createRefund(['payment_intent' => $intent, 'amount' => $refund->amount_minor, 'reason' => $refund->kind === 'duplicate' ? 'duplicate' : 'requested_by_customer',
                'metadata' => ['refund_id' => (string) $refund->id, 'funding_id' => (string) $funding->id]], 'refund_'.$refund->id);
        } catch (PrivacyException $e) {
            if ($e->errorCode === 'PAYMENT_PROVIDER_REJECTED') {
                $this->applyFailure($refund->id, 'PAYMENT_PROVIDER_REJECTED');
            }

            return;
        }
        $this->applyProviderRefund($refund->id, $stripeRefund);
    }

    /** Applies the authoritative Stripe refund object to our refund row (idempotent, monotonic). @param array<string, mixed> $s */
    public function applyProviderRefund(int $refundId, array $s): void
    {
        DB::transaction(function () use ($refundId, $s): void {
            $head = FundingRefund::findOrFail($refundId);
            $headFunding = Funding::findOrFail($head->funding_id);
            Agreement::whereKey($headFunding->agreement_id)->lockForUpdate()->firstOrFail();
            $funding = Funding::whereKey($headFunding->id)->lockForUpdate()->firstOrFail();
            $refund = FundingRefund::whereKey($refundId)->lockForUpdate()->firstOrFail();
            if ($refund->stripe_refund_id === null && isset($s['id'])) {
                $refund->forceFill(['stripe_refund_id' => $s['id'], 'submitted_at' => $refund->submitted_at ?? now()])->save();
            }
            if (in_array($refund->status, [FundingRefund::SUCCEEDED, FundingRefund::REJECTED, FundingRefund::CANCELLED], true)) {
                return;
            }
            match ($s['status'] ?? '') {
                'succeeded' => $this->complete($funding, $refund, $s),
                'failed', 'canceled' => $this->failRow($funding, $refund, is_string($s['failure_reason'] ?? null) ? substr($s['failure_reason'], 0, 60) : 'REFUND_FAILED'),
                default => $this->processing($funding, $refund),
            };
        });
    }

    /** Refund object refetched from Stripe by id (webhook trigger / reconciliation). */
    public function syncByStripeId(string $stripeRefundId): void
    {
        $s = $this->stripe->retrieveRefund($stripeRefundId);
        $refund = FundingRefund::where('stripe_refund_id', $stripeRefundId)->first();
        if ($refund === null && isset($s['metadata']['refund_id'])) {
            $refund = FundingRefund::where(['id' => (int) $s['metadata']['refund_id']])->first();
        }
        if ($refund !== null) {
            $this->applyProviderRefund($refund->id, $s);

            return;
        }
        $funding = is_string($s['payment_intent'] ?? null) ? Funding::where('stripe_payment_intent_id', $s['payment_intent'])->first() : null;
        $this->reconciliation->raise($funding, 'UNKNOWN_REFUND', (string) ($s['id'] ?? ''), ['providerRefundId' => (string) ($s['id'] ?? '')]);
    }

    /** `charge.refunded`-style trigger: refetch our known open refunds and flag provider refunds we never requested. @param array<string, mixed> $charge */
    public function syncForCharge(array $charge): void
    {
        $funding = is_string($charge['payment_intent'] ?? null) ? Funding::where('stripe_payment_intent_id', $charge['payment_intent'])->first() : null;
        if ($funding === null) {
            return;
        }
        foreach (FundingRefund::where('funding_id', $funding->id)->where('status', FundingRefund::PROCESSING)->whereNotNull('stripe_refund_id')->pluck('stripe_refund_id') as $id) {
            $this->syncByStripeId($id);
        }
        $funding->refresh();
        $known = FundingRefund::where('funding_id', $funding->id)->where('kind', 'standard')->whereIn('status', [FundingRefund::SUCCEEDED, FundingRefund::PROCESSING, FundingRefund::APPROVED])->sum('amount_minor');
        if ((int) ($charge['amount_refunded'] ?? 0) > (int) $known) {
            $this->reconciliation->raise($funding, 'REFUND_MISMATCH', 'charge', ['providerRefundedMinor' => (int) $charge['amount_refunded'], 'knownMinor' => (int) $known]);
        }
    }

    /** Reconciliation: resubmit stale approved refunds and re-sync stale processing ones. @return int number of refunds touched */
    public function reconcileStale(): int
    {
        $cut = now()->subMinutes((int) config('payments.reconcile_stale_minutes'));
        $n = 0;
        foreach (FundingRefund::where('status', FundingRefund::APPROVED)->where('updated_at', '<=', $cut)->pluck('id') as $id) {
            $this->reconciliation->raise(Funding::find(FundingRefund::find($id)?->funding_id), 'REFUND_SUBMISSION_STALE', (string) $id, ['refundId' => $id], false);
            $this->submit($id);
            $n++;
        }
        foreach (FundingRefund::where('status', FundingRefund::PROCESSING)->whereNotNull('stripe_refund_id')->where('updated_at', '<=', $cut)->pluck('stripe_refund_id') as $sid) {
            try {
                $this->syncByStripeId($sid);
                $n++;
            } catch (PrivacyException) {
                // provider unavailable: try again next run
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ internals

    private function assertRefundable(Funding $funding, ?int $exceptRefundId = null): void
    {
        if ($funding->status !== Funding::FUNDED) {
            throw new PrivacyException('INVALID_FUNDING_STATE', 'Only a funded service can be refunded.');
        }
        if ($funding->dispute_status === 'open') {
            throw new PrivacyException('REFUND_NOT_ALLOWED', 'A payment dispute is open for this funding.');
        }
        if (FundingRefund::where('funding_id', $funding->id)->where('kind', 'standard')->whereIn('status', FundingRefund::OPEN)->when($exceptRefundId, fn ($q) => $q->where('id', '!=', $exceptRefundId))->exists()) {
            throw new PrivacyException('REFUND_ALREADY_ACTIVE', 'A refund is already in progress for this funding.');
        }
        if ($funding->remainingRefundable() <= 0) {
            throw new PrivacyException('REFUND_EXCEEDS_AVAILABLE', 'Nothing is left to refund.');
        }
    }

    private function assertAmount(Funding $funding, int $amount): void
    {
        if ($amount < 1 || $amount > $funding->remainingRefundable()) {
            throw new PrivacyException('REFUND_EXCEEDS_AVAILABLE', 'The refund amount exceeds what can still be refunded.');
        }
        if (! Money::isProviderRepresentable($amount, $funding->currency)) {
            throw new PrivacyException('AMOUNT_NOT_PROVIDER_REPRESENTABLE', 'This amount cannot be refunded in this currency.');
        }
    }

    /** Creates (or promotes a reviewed) refund as APPROVED, reserves the amount and ends the service on a full refund. */
    private function approve(Funding $funding, Agreement $agreement, string $reason, string $actorType, ?int $actorId, int $amount, ?string $decisionReason, ?FundingRefund $existing): FundingRefund
    {
        $this->assertAmount($funding, $amount);
        $after = Commission::afterRefund($funding->gross_amount_minor, $funding->refunded_amount_minor + $funding->refund_reserved_minor + $amount, $funding->commission_bps);
        $refund = $existing ?? new FundingRefund;
        $attrs = ['amount_minor' => $amount, 'status' => FundingRefund::APPROVED, 'active_key' => 'f'.$funding->id, 'retained_commission_after_minor' => $after['commission'],
            'expert_remaining_after_minor' => $after['expert'], 'version' => ($existing?->version ?? 0) + 1];
        if ($existing === null) {
            $refund->forceFill($attrs + ['funding_id' => $funding->id, 'user_id' => $funding->user_id, 'initiated_by_type' => $actorType, 'initiated_by_id' => $actorId, 'reason_code' => $reason,
                'kind' => 'standard', 'payment_intent_id' => $funding->stripe_payment_intent_id, 'currency' => $funding->currency, 'decision_reason' => $decisionReason]);
        } else {
            $refund->forceFill($attrs + ['decision_reason' => $decisionReason]);
        }
        try {
            $refund->save();
        } catch (UniqueConstraintViolationException) {
            throw new PrivacyException('REFUND_ALREADY_ACTIVE', 'A refund is already in progress for this funding.');
        }
        $funding->forceFill(['refund_reserved_minor' => $funding->refund_reserved_minor + $amount, 'refund_status' => 'pending', 'version' => $funding->version + 1])->save();
        if ($existing === null) {
            $this->ledger->append($funding, 'refund_requested', 'refund_requested:'.$refund->id, ['amount' => $amount, 'actor_type' => $actorType, 'actor_id' => $actorId]);
            $this->notify->user($funding->user_id, 'refund.requested', ['fundingId' => $funding->id, 'refundId' => $refund->id]);
        }
        $this->ledger->append($funding, 'refund_approved', 'refund_approved:'.$refund->id, ['amount' => $amount, 'actor_type' => $actorType, 'actor_id' => $actorId]);
        $this->audit->write($actorType === 'user' ? User::find($actorId) : ($actorType === 'admin' ? Admin::find($actorId) : null), 'refund.approved', 'funding_refund', $refund->id, null, FundingRefund::APPROVED, $reason, ['fundingId' => $funding->id]);
        if ($amount === $funding->gross_amount_minor - $funding->refunded_amount_minor) {
            // The service ends with a full refund: cancel the Agreement and release its booking (no Expert transfer ever existed).
            $actor = $actorType === 'user' ? User::find($actorId) : null;
            $this->agreements->cancelRow($agreement, $actor, $reason === 'EXPERT_CANCELLATION' ? 'EXPERT_UNAVAILABLE' : 'REFUNDED', true);
        }

        return $refund;
    }

    private function openReview(Funding $funding, Agreement $agreement, string $reason, User $user, ?string $note): FundingRefund
    {
        $refund = new FundingRefund;
        $refund->forceFill(['funding_id' => $funding->id, 'user_id' => $funding->user_id, 'initiated_by_type' => 'user', 'initiated_by_id' => $user->id, 'reason_code' => $reason, 'kind' => 'standard',
            'payment_intent_id' => $funding->stripe_payment_intent_id, 'currency' => $funding->currency, 'status' => FundingRefund::PENDING_REVIEW, 'active_key' => 'f'.$funding->id,
            'decision_reason' => $note, 'version' => 1]);
        try {
            $refund->save();
        } catch (UniqueConstraintViolationException) {
            throw new PrivacyException('REFUND_ALREADY_ACTIVE', 'A refund is already in progress for this funding.');
        }
        $funding->forceFill(['refund_status' => 'pending', 'version' => $funding->version + 1])->save();
        $this->ledger->append($funding, 'refund_requested', 'refund_requested:'.$refund->id, ['actor_type' => 'user', 'actor_id' => $user->id]);
        $this->audit->write($user, 'refund.review_requested', 'funding_refund', $refund->id, null, FundingRefund::PENDING_REVIEW, $reason, ['fundingId' => $funding->id, 'agreementId' => $agreement->id]);
        $this->notify->user($funding->user_id, 'refund.requested', ['fundingId' => $funding->id, 'refundId' => $refund->id]);
        $this->notify->admins('refunds.approve', 'refund.review_required', ['fundingId' => $funding->id, 'refundId' => $refund->id]);

        return $refund;
    }

    /** @return array{outcome: string, refund: null, bookingId: int} */
    private function grantReschedule(User $user, Funding $funding, Booking $booking, bool $early): array
    {
        if (! $early && $booking->reschedule_count >= (int) config('commerce.cancellation.late_free_reschedules')) {
            throw new PrivacyException('REFUND_NOT_ALLOWED', 'The free reschedule for this consultation has already been used.');
        }
        $booking->forceFill(['free_reschedule_granted_at' => now()])->save();
        $this->audit->write($user, 'refund.free_reschedule_chosen', 'funding', $funding->id, null, null, 'USER_CANCELLATION', ['bookingId' => $booking->id]);

        return ['outcome' => 'free_reschedule', 'refund' => null, 'bookingId' => $booking->id];
    }

    /** LATE_PAYMENT / DUPLICATE_CHARGE by an Admin: refund one specific stray payment (never the Funding's own confirmed payment). */
    private function approveStray(Admin $admin, Funding $funding, array $d): FundingRefund
    {
        $attempt = FundingAttempt::where(['funding_id' => $funding->id, 'status' => FundingAttempt::SUCCEEDED])->find((int) ($d['attemptId'] ?? 0));
        if ($attempt === null || $attempt->stripe_payment_intent_id === null || $attempt->stripe_payment_intent_id === $funding->stripe_payment_intent_id) {
            throw new PrivacyException('REFUND_REASON_NOT_VERIFIED', 'No stray payment was found for that attempt.');
        }
        $pi = $attempt->stripe_payment_intent_id;
        if (FundingRefund::where('payment_intent_id', $pi)->where('kind', 'duplicate')->whereIn('status', [...FundingRefund::OPEN, FundingRefund::SUCCEEDED])->exists()) {
            throw new PrivacyException('REFUND_ALREADY_ACTIVE', 'That payment already has a refund.');
        }
        $refund = new FundingRefund;
        $refund->forceFill(['funding_id' => $funding->id, 'user_id' => $funding->user_id, 'initiated_by_type' => 'admin', 'initiated_by_id' => $admin->id, 'reason_code' => $d['reasonCode'], 'kind' => 'duplicate',
            'payment_intent_id' => $pi, 'amount_minor' => $funding->gross_amount_minor, 'currency' => $funding->currency, 'status' => FundingRefund::APPROVED, 'active_key' => 'd'.$pi,
            'decision' => 'approve_full', 'decision_reason' => $d['reason'], 'reviewed_by_admin_id' => $admin->id, 'decided_at' => now(), 'version' => 1]);
        try {
            $refund->save();
        } catch (UniqueConstraintViolationException) {
            throw new PrivacyException('REFUND_ALREADY_ACTIVE', 'That payment already has a refund.');
        }
        $this->ledger->append($funding, 'refund_approved', 'refund_approved:'.$refund->id, ['amount' => $refund->amount_minor, 'actor_type' => 'admin', 'actor_id' => $admin->id, 'provider_ref' => $pi]);
        $this->audit->write($admin, 'refund.approved', 'funding_refund', $refund->id, null, FundingRefund::APPROVED, $d['reasonCode'], ['fundingId' => $funding->id]);

        return $refund;
    }

    private function processing(Funding $funding, FundingRefund $refund): void
    {
        if ($refund->status !== FundingRefund::APPROVED) {
            return;
        }
        $refund->forceFill(['status' => FundingRefund::PROCESSING, 'version' => $refund->version + 1])->save();
        $this->ledger->append($funding, 'refund_processing', 'refund_processing:'.$refund->id, ['amount' => $refund->amount_minor, 'provider_ref' => $refund->stripe_refund_id]);
    }

    /** @param array<string, mixed> $s */
    private function complete(Funding $funding, FundingRefund $refund, array $s): void
    {
        if ((int) ($s['amount'] ?? -1) !== $refund->amount_minor) {
            $this->reconciliation->raise($funding, 'REFUND_MISMATCH', 'refund:'.$refund->id, ['refundId' => $refund->id]);

            return;
        }
        $refund->forceFill(['status' => FundingRefund::SUCCEEDED, 'active_key' => null, 'completed_at' => now(), 'version' => $refund->version + 1])->save();
        if ($refund->kind === 'standard') {
            $refunded = $funding->refunded_amount_minor + $refund->amount_minor;
            $after = Commission::afterRefund($funding->gross_amount_minor, $refunded, $funding->commission_bps); // the rate sealed at funding time, never today's config
            $funding->forceFill(['refund_reserved_minor' => max(0, $funding->refund_reserved_minor - $refund->amount_minor), 'refunded_amount_minor' => $refunded,
                'retained_commission_minor' => $after['commission'], 'expert_remaining_minor' => $after['expert'], 'refund_status' => $refunded >= $funding->gross_amount_minor ? 'refunded' : 'partially_refunded',
                'version' => $funding->version + 1])->save();
        } else {
            $this->reconciliation->resolve($funding, 'DUPLICATE_PAYMENT', 'REFUNDED');
            $this->reconciliation->resolve($funding, 'LATE_PAYMENT', 'REFUNDED');
        }
        $this->ledger->append($funding, 'refund_completed', 'refund_completed:'.$refund->id, ['amount' => $refund->amount_minor, 'provider_ref' => $refund->stripe_refund_id]);
        $this->audit->write(null, 'refund.completed', 'funding_refund', $refund->id, null, FundingRefund::SUCCEEDED, null, ['fundingId' => $funding->id]);
        $this->notify->user($funding->user_id, 'refund.completed', ['fundingId' => $funding->id, 'refundId' => $refund->id]);
    }

    private function applyFailure(int $refundId, string $code): void
    {
        DB::transaction(function () use ($refundId, $code): void {
            $head = FundingRefund::findOrFail($refundId);
            $headFunding = Funding::findOrFail($head->funding_id);
            Agreement::whereKey($headFunding->agreement_id)->lockForUpdate()->firstOrFail();
            $funding = Funding::whereKey($headFunding->id)->lockForUpdate()->firstOrFail();
            $refund = FundingRefund::whereKey($refundId)->lockForUpdate()->firstOrFail();
            if (in_array($refund->status, FundingRefund::OPEN, true)) {
                $this->failRow($funding, $refund, $code);
            }
        });
    }

    private function failRow(Funding $funding, FundingRefund $refund, string $code): void
    {
        $refund->forceFill(['status' => FundingRefund::FAILED, 'failure_code' => $code, 'active_key' => null, 'version' => $refund->version + 1])->save();
        if ($refund->kind === 'standard') {
            $funding->forceFill(['refund_reserved_minor' => max(0, $funding->refund_reserved_minor - $refund->amount_minor), 'version' => $funding->version + 1])->save();
            $this->refreshRefundStatus($funding, true);
        }
        $this->ledger->append($funding, 'refund_failed', 'refund_failed:'.$refund->id, ['amount' => $refund->amount_minor, 'provider_ref' => $refund->stripe_refund_id]);
        $this->notify->user($funding->user_id, 'refund.failed', ['fundingId' => $funding->id, 'refundId' => $refund->id]);
        $this->notify->admins('refunds.approve', 'refund.review_required', ['fundingId' => $funding->id, 'refundId' => $refund->id]);
    }

    private function refreshRefundStatus(Funding $funding, bool $failed = false): void
    {
        $open = FundingRefund::where('funding_id', $funding->id)->where('kind', 'standard')->whereIn('status', FundingRefund::OPEN)->exists();
        $status = match (true) {
            $funding->refunded_amount_minor >= $funding->gross_amount_minor => 'refunded',
            $open => 'pending',
            $funding->refunded_amount_minor > 0 => 'partially_refunded',
            $failed => 'failed',
            default => 'none',
        };
        if ($funding->refund_status !== $status) {
            $funding->forceFill(['refund_status' => $status])->save();
        }
    }
}
