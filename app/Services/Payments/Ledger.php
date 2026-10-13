<?php

namespace App\Services\Payments;

use App\Models\Funding;
use App\Models\LedgerEvent;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Append-only financial ledger writer. Each event has a deterministic dedupe key, so a replayed webhook, retried job or
 * concurrent reconciliation can never create a second entry for the same financial fact. Content is ids and amounts only.
 */
class Ledger
{
    public const TYPES = ['funding_created', 'checkout_created', 'payment_processing', 'payment_confirmed', 'payment_failed', 'funding_expired', 'funding_cancelled', 'refund_requested', 'refund_approved',
        'refund_rejected', 'refund_processing', 'refund_completed', 'refund_failed', 'dispute_opened', 'dispute_won', 'dispute_lost', 'dispute_closed', 'duplicate_payment_detected',
        'late_payment_received', 'reconciliation_corrected'];

    /**
     * @param  array{amount?: int|null, actor_type?: string, actor_id?: int|null, provider_ref?: string|null, correlation_ref?: string|null, occurred_at?: mixed}  $o
     */
    public function append(Funding $funding, string $type, string $dedupeKey, array $o = []): LedgerEvent
    {
        $existing = LedgerEvent::where('dedupe_key', $dedupeKey)->first();
        if ($existing !== null) {
            return $existing;
        }
        $event = new LedgerEvent;
        $event->forceFill([
            'funding_id' => $funding->id, 'agreement_id' => $funding->agreement_id, 'case_id' => $funding->case_id, 'event_type' => $type,
            'actor_type' => $o['actor_type'] ?? 'system', 'actor_id' => $o['actor_id'] ?? null,
            'gross_amount_minor' => $funding->gross_amount_minor, 'amount_minor' => $o['amount'] ?? null, 'currency' => $funding->currency,
            'platform_commission_minor' => $funding->retained_commission_minor, 'expert_provisional_minor' => $funding->expert_remaining_minor,
            'provider_ref' => $o['provider_ref'] ?? null, 'correlation_ref' => $o['correlation_ref'] ?? (string) request()->attributes->get('privacy_request_id'),
            'dedupe_key' => $dedupeKey, 'occurred_at' => $o['occurred_at'] ?? now(), 'created_at' => now(),
        ]);
        try {
            $event->save();
        } catch (UniqueConstraintViolationException) {
            return LedgerEvent::where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        return $event;
    }
}
