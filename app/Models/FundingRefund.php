<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A refund request/decision/execution for a Funding. Money state follows Stripe; history is never deleted. */
class FundingRefund extends Model
{
    public const PENDING_REVIEW = 'pending_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Non-terminal states that hold the one-active-refund key. */
    public const OPEN = [self::PENDING_REVIEW, self::APPROVED, self::PROCESSING];

    protected $guarded = ['*'];

    protected $hidden = ['stripe_refund_id'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Financial records require an approved retention process.'));
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'retained_commission_after_minor' => 'integer', 'expert_remaining_after_minor' => 'integer', 'version' => 'integer',
            'decision_reason' => 'encrypted', 'decided_at' => 'datetime', 'submitted_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Funding, $this> */
    public function funding(): BelongsTo
    {
        return $this->belongsTo(Funding::class);
    }
}
