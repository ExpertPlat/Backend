<?php

namespace App\Services\Payments;

use App\Models\Funding;
use App\Models\ReconciliationIssue;
use Illuminate\Database\UniqueConstraintViolationException;

/** Records financial inconsistencies for Admin review. One open issue per (kind, funding, reference); repeats never duplicate. */
class Reconciliation
{
    public const KINDS = ['LATE_PAYMENT', 'DUPLICATE_PAYMENT', 'AMOUNT_MISMATCH', 'STALE_PROCESSING', 'PROVIDER_PAID_INTERNAL_PENDING', 'INTERNAL_FUNDED_PROVIDER_UNPAID',
        'REFUND_MISMATCH', 'DISPUTE_MISMATCH', 'UNKNOWN_REFUND', 'REFUND_SUBMISSION_STALE', 'ACCOUNT_SYNC_FAILED'];

    public function __construct(private FinanceNotifier $notify) {}

    /** @param array<string, int|string|null> $detail Codes and ids only. */
    public function raise(?Funding $funding, string $kind, string $reference = '', array $detail = [], bool $notifyAdmins = true): ReconciliationIssue
    {
        $key = $kind.':'.($funding?->id ?? 0).':'.$reference;
        $existing = ReconciliationIssue::where('open_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $issue = new ReconciliationIssue;
        $issue->forceFill(['funding_id' => $funding?->id, 'kind' => $kind, 'status' => ReconciliationIssue::OPEN, 'open_key' => $key, 'detail' => $detail ?: null, 'detected_at' => now()]);
        try {
            $issue->save();
        } catch (UniqueConstraintViolationException) {
            return ReconciliationIssue::where('open_key', $key)->firstOrFail();
        }
        if ($notifyAdmins) {
            $this->notify->admins('finance.view', 'reconciliation.failed', ['reconciliationIssueId' => $issue->id] + ($funding ? ['fundingId' => $funding->id] : []));
        }

        return $issue;
    }

    /** Resolves every open issue of a kind for a funding (used when a later sync heals the inconsistency). */
    public function resolve(Funding $funding, string $kind, string $resolution): int
    {
        return ReconciliationIssue::where(['funding_id' => $funding->id, 'kind' => $kind, 'status' => ReconciliationIssue::OPEN])
            ->update(['status' => ReconciliationIssue::RESOLVED, 'open_key' => null, 'resolution' => $resolution, 'resolved_at' => now(), 'updated_at' => now()]);
    }
}
