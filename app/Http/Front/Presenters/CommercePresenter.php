<?php

namespace App\Http\Front\Presenters;

use App\Http\Front\Front;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\CaseRecord;
use App\Models\FinancialDocument;
use App\Models\Funding;
use App\Models\FundingAttempt;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Support\Money;

/** Proposals, agreements, bookings and fundings in the v7 shapes (services/mock/presenters.js). */
final class CommercePresenter
{
    /** API categories ↔ v7 chips. */
    public const CATEGORY_OUT = ['timeline' => 'time'];

    public static function money(int $minor, string $currency): array
    {
        return ['amountMinor' => $minor, 'currency' => $currency, 'minorUnitExponent' => Money::exponent($currency)];
    }

    public static function version(ProposalVersion $v): array
    {
        $v->loadMissing('milestones');
        $c = $v->content;
        $days = $v->valid_until && $v->created_at ? max(1, (int) round($v->created_at->diffInHours($v->valid_until) / 24)) : null;

        return ['versionNo' => $v->version_no, 'scopeSummary' => $c['scopeSummary'], 'inputsRequired' => $c['inputsRequired'] ?? '',
            'deliverables' => array_map(fn ($d) => ['title' => $d['title'], 'description' => $d['description'] ?? ''], $c['deliverables']), 'exclusions' => $c['exclusions'] ?? [],
            'clarificationAllowance' => $c['clarificationAllowance'], 'reviewPeriodHours' => $c['reviewPeriodHours'], 'consultationMinutes' => $c['consultationMinutes'] ?? $v->consultation_minutes,
            'currency' => $v->currency, 'milestones' => $v->milestones->sortBy('sequence')->map(fn ($m) => ['title' => $m->title, 'description' => $m->description ?? '', 'amountMinor' => $m->amount_minor, 'dueAt' => Front::iso($m->due_at)])->values()->all(),
            'validityDays' => $days, 'validUntil' => Front::iso($v->valid_until), 'createdAt' => Front::iso($v->created_at),
            'totalAmountMinor' => $v->total_amount_minor, 'amount' => self::money($v->total_amount_minor, $v->currency)];
    }

    public static function proposal(Proposal $p, string $realm): array
    {
        $p->loadMissing(['versions.milestones', 'expert.profile', 'caseRecord.currentIntake', 'caseRecord.owner']);
        $versions = $p->versions->sortBy('version_no')->values();
        $current = $versions->firstWhere('version_no', $p->current_version_no) ?? $versions->last();
        $out = ['id' => $p->id, 'caseId' => $p->case_id, 'invitationId' => $p->invitation_id, 'scopeId' => $p->scope_id, 'status' => $p->status, 'version' => $p->version,
            'versionNo' => $p->current_version_no, 'current' => $current ? self::version($current) : null, 'versions' => $versions->map(fn ($v) => self::version($v))->all(),
            'changeRequests' => $p->changeRequests()->orderBy('id')->get()->map(fn ($r) => ['id' => $r->id, 'versionNo' => $r->version_no,
                'categories' => array_values(array_map(fn ($c) => self::CATEGORY_OUT[$c] ?? $c, $r->categories ?? [])), 'message' => $r->message, 'status' => $r->status, 'createdAt' => Front::iso($r->created_at)])->all(),
            'expiresAt' => Front::iso($p->valid_until), 'closedAt' => Front::iso($p->closed_at), 'createdAt' => Front::iso($p->created_at), 'updatedAt' => Front::iso($p->updated_at),
            'agreementId' => $p->agreement?->id];
        if ($realm === 'user') {
            $out['expert'] = ExpertPresenter::public($p->expert);
        } else {
            $out['client'] = ['firstName' => MatchingPresenter::firstName($p->caseRecord?->owner?->name)];
            $out['caseTitle'] = $p->caseRecord?->currentIntake?->payload['title'] ?? null;
        }

        return $out;
    }

    /** API agreement state → v7 state (funded = active work). */
    public static function agreementStatus(Agreement $a): string
    {
        return match ($a->status) {
            Agreement::FUNDED => Booking::where('agreement_id', $a->id)->where('status', 'completed')->exists() ? 'completed' : 'active',
            Agreement::FUNDING_EXPIRED => 'awaiting_payment',
            default => $a->status,
        };
    }

    public static function split(Agreement $a): array
    {
        $bps = (int) config('payments.commission_bps');
        $fee = intdiv($a->total_amount_minor * $bps + 5000, 10000);

        return ['grossMinor' => $a->total_amount_minor, 'platformFeeMinor' => $fee, 'expertNetMinor' => $a->total_amount_minor - $fee, 'currency' => $a->currency,
            'minorUnitExponent' => Money::exponent($a->currency), 'platformFeePercent' => $bps / 100];
    }

    public static function agreement(Agreement $a, string $realm): array
    {
        $a->loadMissing(['expert.profile', 'milestones']);
        $content = $a->snapshot['proposal']['content'] ?? [];
        $case = CaseRecord::with(['currentIntake', 'owner'])->find($a->case_id);
        $funding = Funding::where('agreement_id', $a->id)->orderByDesc('id')->first();
        $booking = Booking::where('agreement_id', $a->id)->whereIn('status', Booking::ACTIVE)->orderByDesc('id')->first()
            ?? Booking::where('agreement_id', $a->id)->where('status', '!=', Booking::CANCELLED)->orderByDesc('id')->first();
        $out = ['id' => $a->id, 'version' => $a->version, 'caseId' => $a->case_id, 'proposalId' => $a->proposal_id, 'status' => self::agreementStatus($a),
            'amount' => self::money($a->total_amount_minor, $a->currency), 'consultationMinutes' => $a->consultation_minutes,
            'scopeSummary' => $content['scopeSummary'] ?? null, 'deliverables' => array_map(fn ($x) => ['title' => $x['title'], 'description' => $x['description'] ?? ''], $content['deliverables'] ?? []), 'exclusions' => $content['exclusions'] ?? [],
            'milestones' => $a->milestones->sortBy('sequence')->map(fn ($m) => ['title' => $m->title, 'description' => $m->description ?? '', 'amountMinor' => $m->amount_minor])->values()->all(),
            'caseTitle' => $case?->currentIntake?->payload['title'] ?? null, 'funding' => $funding ? ['id' => $funding->id, 'status' => self::fundingStatus($funding)] : null,
            'booking' => $booking ? self::booking($booking) : null, 'acceptedAt' => Front::iso($a->accepted_at), 'cancelledAt' => Front::iso($a->cancelled_at),
            'cancelReasonCode' => $a->cancel_reason_code, 'createdAt' => Front::iso($a->created_at), 'updatedAt' => Front::iso($a->updated_at)];
        if ($realm === 'user') {
            $out['expert'] = ExpertPresenter::public($a->expert);
        } else {
            $out['client'] = ['firstName' => MatchingPresenter::firstName($case?->owner?->name)];
            $out['split'] = self::split($a);
        }

        return $out;
    }

    public static function booking(Booking $b): array
    {
        return ['id' => $b->id, 'version' => $b->version, 'caseId' => $b->case_id, 'agreementId' => $b->agreement_id, 'expertId' => $b->expert_id, 'status' => $b->status,
            'startsAt' => Front::iso($b->start_at), 'endsAt' => Front::iso($b->end_at), 'durationMinutes' => $b->duration_minutes,
            'heldUntil' => $b->status === Booking::HELD ? Front::iso($b->hold_expires_at) : null, 'rescheduleCount' => $b->reschedule_count, 'createdAt' => Front::iso($b->created_at)];
    }

    /** API funding state → v7 state. */
    public static function fundingStatus(Funding $f): string
    {
        if ($f->status === Funding::FUNDED && in_array($f->refund_status, ['refunded', 'partially_refunded'], true)) {
            return $f->refund_status;
        }
        if ($f->status === Funding::FUNDED && in_array($f->refund_status, ['pending', 'requested', 'pending_review'], true)) {
            return 'refund_requested';
        }

        return match ($f->status) {
            Funding::AWAITING_PAYMENT => 'requires_payment',
            Funding::REQUIRES_ACTION => 'processing',
            Funding::CANCELLED => 'failed',
            default => $f->status,
        };
    }

    public static function funding(?Funding $f): ?array
    {
        if (! $f) {
            return null;
        }
        $attempt = FundingAttempt::where('funding_id', $f->id)->whereIn('status', [FundingAttempt::OPEN, FundingAttempt::PROCESSING])->orderByDesc('id')->first();

        return ['id' => $f->id, 'agreementId' => $f->agreement_id, 'caseId' => $f->case_id, 'status' => self::fundingStatus($f), 'refundStatus' => $f->refund_status ?? 'none', 'version' => $f->version,
            'amounts' => ['currency' => $f->currency, 'minorUnitExponent' => Money::exponent($f->currency), 'grossMinor' => $f->gross_amount_minor, 'refundedMinor' => $f->refunded_amount_minor,
                'remainingRefundableMinor' => $f->remainingRefundable()],
            'deadlineAt' => Front::iso($f->deadline_at), 'fundedAt' => Front::iso($f->funded_at),
            'currentAttempt' => $attempt ? ['id' => $attempt->id, 'status' => $attempt->status === FundingAttempt::OPEN ? 'awaiting_provider' : $attempt->status, 'expiresAt' => Front::iso($attempt->expires_at)] : null,
            'receiptAvailable' => FinancialDocument::where(['funding_id' => $f->id, 'document_type' => FinancialDocument::RECEIPT])->exists(), 'createdAt' => Front::iso($f->created_at)];
    }
}
