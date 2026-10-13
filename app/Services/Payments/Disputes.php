<?php

namespace App\Services\Payments;

use App\Models\Agreement;
use App\Models\Funding;
use App\Models\FundingAttempt;
use App\Models\FundingDispute;
use App\Services\Privacy\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Payment dispute foundation (DR-089). SolveIt handles Stripe disputes: persist the state, append the ledger, freeze any future Expert release,
 * notify Admins. It never refunds, debits an Expert or marks the service refunded on its own. Evidence submission is out of scope.
 */
class Disputes
{
    private const OPEN = ['needs_response', 'under_review', 'warning_needs_response', 'warning_under_review'];

    public function __construct(private StripeGateway $stripe, private Ledger $ledger, private FinanceNotifier $notify, private Reconciliation $reconciliation, private AuditWriter $audit) {}

    public function syncByStripeId(string $disputeId): void
    {
        $this->apply($this->stripe->retrieveDispute($disputeId));
    }

    /** @param array<string, mixed> $s */
    public function apply(array $s): void
    {
        $pi = is_string($s['payment_intent'] ?? null) ? $s['payment_intent'] : null;
        $fundingId = $pi === null ? null : (Funding::where('stripe_payment_intent_id', $pi)->value('id') ?? FundingAttempt::where('stripe_payment_intent_id', $pi)->value('funding_id'));
        if ($fundingId === null) {
            $this->reconciliation->raise(null, 'DISPUTE_MISMATCH', (string) ($s['id'] ?? ''), ['providerDisputeId' => (string) ($s['id'] ?? '')]);

            return;
        }
        DB::transaction(function () use ($s, $fundingId): void {
            $head = Funding::findOrFail($fundingId);
            Agreement::whereKey($head->agreement_id)->lockForUpdate()->firstOrFail();
            $funding = Funding::whereKey($fundingId)->lockForUpdate()->firstOrFail();
            $status = (string) ($s['status'] ?? '');
            $state = match (true) {
                in_array($status, self::OPEN, true) => 'open',
                $status === 'won' => 'won',
                $status === 'lost' => 'lost',
                default => 'closed',
            };
            $row = FundingDispute::where('stripe_dispute_id', $s['id'])->lockForUpdate()->first();
            $new = $row === null;
            $row ??= new FundingDispute;
            $row->forceFill([
                'funding_id' => $funding->id, 'stripe_dispute_id' => $s['id'], 'status' => $status, 'reason_code' => is_string($s['reason'] ?? null) ? substr($s['reason'], 0, 60) : null,
                'amount_minor' => (int) ($s['amount'] ?? 0), 'currency' => strtoupper((string) ($s['currency'] ?? $funding->currency)),
                'evidence_due_by' => isset($s['evidence_details']['due_by']) ? CarbonImmutable::createFromTimestampUTC((int) $s['evidence_details']['due_by']) : null,
                'opened_at' => $row->opened_at ?? CarbonImmutable::createFromTimestampUTC((int) ($s['created'] ?? now()->getTimestamp())),
                'closed_at' => $state === 'open' ? null : ($row->closed_at ?? now()),
            ])->save();

            $stillOpen = FundingDispute::where('funding_id', $funding->id)->whereIn('status', self::OPEN)->exists();
            $aggregate = $stillOpen ? 'open' : $state;
            $freeze = $stillOpen || $state === 'lost' || $funding->dispute_status === 'lost';
            $funding->forceFill(['dispute_status' => $funding->dispute_status === 'lost' && $aggregate !== 'open' ? 'lost' : $aggregate,
                'release_frozen_at' => $freeze ? ($funding->release_frozen_at ?? now()) : null, 'version' => $funding->version + 1])->save();

            $type = match ($state) {
                'open' => 'dispute_opened', 'won' => 'dispute_won', 'lost' => 'dispute_lost', default => 'dispute_closed'
            };
            $this->ledger->append($funding, $type, $type.':'.$row->id, ['amount' => $row->amount_minor, 'provider_ref' => $row->stripe_dispute_id]);
            if ($new && $state === 'open') {
                $this->audit->write(null, 'funding.dispute_opened', 'funding', $funding->id, null, 'open', null, ['agreementId' => $funding->agreement_id]);
                $this->notify->admins('disputes.view', 'dispute.opened', ['fundingId' => $funding->id, 'disputeId' => $row->id]);
            }
        });
    }
}
