<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * The commercial funding obligation of one Agreement (DR-087). The amount, currency and commission snapshot are sealed at creation;
 * refund, dispute and (future) transfer are separate dimensions and never overload `status`.
 */
class Funding extends Model
{
    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const REQUIRES_ACTION = 'requires_action';

    public const PROCESSING = 'processing';

    public const FUNDED = 'funded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Statuses that hold the one-active-Funding-per-Agreement key. */
    public const ACTIVE = [self::AWAITING_PAYMENT, self::REQUIRES_ACTION, self::PROCESSING, self::FAILED, self::FUNDED];

    /** Statuses in which money has not been confirmed and the funding window still applies. */
    public const UNFUNDED_OPEN = [self::AWAITING_PAYMENT, self::REQUIRES_ACTION, self::PROCESSING, self::FAILED];

    private const SEALED = ['agreement_id', 'user_id', 'expert_id', 'case_id', 'gross_amount_minor', 'currency', 'original_amount_minor', 'discount_amount_minor',
        'commission_bps', 'platform_commission_minor', 'expert_provisional_minor'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $f): void {
            if (array_intersect(array_keys($f->getDirty()), self::SEALED) !== []) {
                throw new LogicException('The financial snapshot of a Funding is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Financial records require an approved retention process.'));
    }

    protected function casts(): array
    {
        return ['gross_amount_minor' => 'integer', 'original_amount_minor' => 'integer', 'discount_amount_minor' => 'integer', 'commission_bps' => 'integer',
            'platform_commission_minor' => 'integer', 'expert_provisional_minor' => 'integer', 'retained_commission_minor' => 'integer', 'expert_remaining_minor' => 'integer',
            'refunded_amount_minor' => 'integer', 'refund_reserved_minor' => 'integer', 'provider_fee_minor' => 'integer', 'version' => 'integer',
            'deadline_at' => 'datetime', 'deadline_basis_at' => 'datetime', 'funded_at' => 'datetime', 'expired_at' => 'datetime', 'release_frozen_at' => 'datetime'];
    }

    public function remainingRefundable(): int
    {
        return max(0, $this->gross_amount_minor - $this->refunded_amount_minor - $this->refund_reserved_minor);
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** @return HasMany<FundingAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(FundingAttempt::class)->orderBy('attempt_no');
    }

    /** @return HasMany<FundingRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(FundingRefund::class)->orderBy('id');
    }

    /** @return HasMany<FinancialDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(FinancialDocument::class)->orderBy('id');
    }

    /** @return HasMany<LedgerEvent, $this> */
    public function ledger(): HasMany
    {
        return $this->hasMany(LedgerEvent::class)->orderBy('id');
    }
}
