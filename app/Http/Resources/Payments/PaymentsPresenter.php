<?php

namespace App\Http\Resources\Payments;

use App\Models\FinancialDocument;
use App\Models\Funding;
use App\Models\FundingAttempt;
use App\Models\FundingDispute;
use App\Models\FundingRefund;
use App\Models\LedgerEvent;
use App\Models\ReconciliationIssue;
use App\Models\StripeAccount;
use App\Support\Money;

/**
 * Serializers for payments-contract-v1. Public/private separation is explicit: nothing here carries Case text, card data, Stripe account ids,
 * provider secrets or checkout URLs outside the one Checkout response. Experts never see payment details, only funding status and their contractual split.
 */
final class PaymentsPresenter
{
    private static function utc(mixed $t): ?string
    {
        return $t === null ? null : $t->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** @return array<string, mixed> */
    private static function amounts(Funding $f): array
    {
        return ['currency' => $f->currency, 'minorUnitExponent' => Money::exponent($f->currency), 'grossMinor' => $f->gross_amount_minor, 'originalMinor' => $f->original_amount_minor,
            'discountMinor' => $f->discount_amount_minor, 'finalMinor' => $f->gross_amount_minor, 'refundedMinor' => $f->refunded_amount_minor, 'remainingRefundableMinor' => $f->remainingRefundable()];
    }

    /** User view. @return array<string, mixed> */
    public static function funding(Funding $f): array
    {
        $attempt = FundingAttempt::where('funding_id', $f->id)->whereIn('status', [FundingAttempt::OPEN, FundingAttempt::PROCESSING])->orderByDesc('id')->first();

        return ['id' => $f->id, 'agreementId' => $f->agreement_id, 'caseId' => $f->case_id, 'status' => $f->status, 'refundStatus' => $f->refund_status, 'version' => $f->version,
            'amounts' => self::amounts($f), 'deadlineAt' => self::utc($f->deadline_at), 'deadlineRule' => $f->deadline_rule, 'fundedAt' => self::utc($f->funded_at), 'expiredAt' => self::utc($f->expired_at),
            'currentAttempt' => $attempt ? ['id' => $attempt->id, 'status' => $attempt->status, 'expiresAt' => self::utc($attempt->expires_at)] : null,
            'receiptAvailable' => FinancialDocument::where(['funding_id' => $f->id, 'document_type' => FinancialDocument::RECEIPT])->exists(),
            'noPayoutNote' => 'Funding is held by the platform provider. No payment has been released to the Expert.', 'createdAt' => self::utc($f->created_at)];
    }

    /** Expert view: status and their contractual split only. @return array<string, mixed> */
    public static function fundingForExpert(Funding $f): array
    {
        return ['id' => $f->id, 'agreementId' => $f->agreement_id, 'status' => $f->status, 'currency' => $f->currency, 'deadlineAt' => self::utc($f->deadline_at), 'fundedAt' => self::utc($f->funded_at),
            'refundStatus' => $f->refund_status, 'split' => ['grossMinor' => $f->gross_amount_minor, 'platformCommissionMinor' => $f->retained_commission_minor, 'expertProvisionalMinor' => $f->expert_remaining_minor,
                'commissionBasisPoints' => $f->commission_bps], 'payoutNote' => 'Provisional amount only. No payout has been made.'];
    }

    /** @return array<string, mixed> */
    public static function fundingForAdmin(Funding $f, bool $detail = false): array
    {
        $item = self::funding($f) + ['userId' => $f->user_id, 'expertId' => $f->expert_id, 'disputeStatus' => $f->dispute_status, 'releaseFrozen' => $f->release_frozen_at !== null,
            'commissionBasisPoints' => $f->commission_bps, 'platformCommissionMinor' => $f->platform_commission_minor, 'expertProvisionalMinor' => $f->expert_provisional_minor,
            'retainedCommissionMinor' => $f->retained_commission_minor, 'expertRemainingMinor' => $f->expert_remaining_minor, 'providerFeeMinor' => $f->provider_fee_minor, 'refundReservedMinor' => $f->refund_reserved_minor];
        if ($detail) {
            $item['attempts'] = $f->attempts()->get()->map(fn (FundingAttempt $a) => ['id' => $a->id, 'attemptNo' => $a->attempt_no, 'status' => $a->status, 'failureCode' => $a->failure_code,
                'expiresAt' => self::utc($a->expires_at), 'providerPaidAt' => self::utc($a->provider_paid_at)])->all();
            $item['ledger'] = $f->ledger()->get()->map(fn (LedgerEvent $e) => self::ledger($e))->all();
            $item['refunds'] = $f->refunds()->get()->map(fn (FundingRefund $r) => self::refund($r, true))->all();
        }

        return $item;
    }

    /** @return array<string, mixed> */
    public static function ledger(LedgerEvent $e): array
    {
        return ['id' => $e->id, 'fundingId' => $e->funding_id, 'agreementId' => $e->agreement_id, 'eventType' => $e->event_type, 'actorType' => $e->actor_type, 'currency' => $e->currency,
            'grossMinor' => $e->gross_amount_minor, 'amountMinor' => $e->amount_minor, 'platformCommissionMinor' => $e->platform_commission_minor, 'expertProvisionalMinor' => $e->expert_provisional_minor,
            'providerRef' => $e->provider_ref, 'occurredAt' => self::utc($e->occurred_at)];
    }

    /** @return array<string, mixed> */
    public static function refund(FundingRefund $r, bool $admin = false): array
    {
        $item = ['id' => $r->id, 'fundingId' => $r->funding_id, 'status' => $r->status, 'reasonCode' => $r->reason_code, 'kind' => $r->kind, 'currency' => $r->currency, 'amountMinor' => $r->amount_minor,
            'version' => $r->version, 'decision' => $r->decision, 'completedAt' => self::utc($r->completed_at), 'createdAt' => self::utc($r->created_at)];
        if ($admin) {
            $item += ['initiatedByType' => $r->initiated_by_type, 'decisionReason' => $r->decision_reason, 'reviewedByAdminId' => $r->reviewed_by_admin_id, 'decidedAt' => self::utc($r->decided_at),
                'retainedCommissionAfterMinor' => $r->retained_commission_after_minor, 'expertRemainingAfterMinor' => $r->expert_remaining_after_minor, 'failureCode' => $r->failure_code];
        }

        return $item;
    }

    /** @return array<string, mixed> */
    public static function document(FinancialDocument $d): array
    {
        return ['id' => $d->id, 'fundingId' => $d->funding_id, 'type' => $d->document_type, 'number' => $d->number, 'status' => $d->status, 'currency' => $d->currency,
            'amountMinor' => $d->amount_minor, 'issuedAt' => self::utc($d->issued_at), 'taxInvoice' => false, 'providerReceiptUrl' => $d->provider_receipt_url,
            'notice' => $d->document_type === FinancialDocument::INVOICE ? 'Amount due. This is not a tax invoice.' : 'Amount paid. This is not a tax invoice.'];
    }

    /** @return array<string, mixed> */
    public static function account(?StripeAccount $a): array
    {
        if ($a === null) {
            return ['status' => 'not_started', 'detailsSubmitted' => false, 'chargesEnabled' => false, 'payoutsEnabled' => false, 'requirementsDue' => [], 'disabledReason' => null,
                'onboardingCompleted' => false, 'lastSyncedAt' => null, 'note' => 'Stripe onboarding does not replace SolveIt verification. No payout has been made.'];
        }
        $completed = (bool) ($a->details_submitted && $a->payouts_enabled);

        return ['status' => $a->disabled_reason !== null ? 'restricted' : ($completed ? 'enabled' : 'onboarding_started'), 'detailsSubmitted' => (bool) $a->details_submitted, 'chargesEnabled' => (bool) $a->charges_enabled,
            'payoutsEnabled' => (bool) $a->payouts_enabled, 'requirementsDue' => $a->requirements_due ?? [], 'disabledReason' => $a->disabled_reason, 'onboardingCompleted' => $completed,
            'lastSyncedAt' => self::utc($a->last_synced_at), 'note' => 'Stripe onboarding does not replace SolveIt verification. No payout has been made.'];
    }

    /** @return array<string, mixed> */
    public static function accountForAdmin(StripeAccount $a): array
    {
        return self::account($a) + ['expertId' => $a->expert_id, 'onboardingStartedAt' => self::utc($a->onboarding_started_at)];
    }

    /** @return array<string, mixed> */
    public static function issue(ReconciliationIssue $i): array
    {
        return ['id' => $i->id, 'fundingId' => $i->funding_id, 'kind' => $i->kind, 'status' => $i->status, 'detail' => $i->detail ?? (object) [], 'resolution' => $i->resolution,
            'detectedAt' => self::utc($i->detected_at), 'resolvedAt' => self::utc($i->resolved_at)];
    }

    /** @return array<string, mixed> */
    public static function dispute(FundingDispute $d): array
    {
        return ['id' => $d->id, 'fundingId' => $d->funding_id, 'status' => $d->status, 'reasonCode' => $d->reason_code, 'currency' => $d->currency, 'amountMinor' => $d->amount_minor,
            'evidenceDueBy' => self::utc($d->evidence_due_by), 'openedAt' => self::utc($d->opened_at), 'closedAt' => self::utc($d->closed_at)];
    }
}
