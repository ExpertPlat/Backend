<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One Stripe Checkout Session trying to satisfy a Funding. Attempts are never overwritten or deleted. */
class FundingAttempt extends Model
{
    public const OPEN = 'open';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['*'];

    protected $hidden = ['checkout_url'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Financial records require an approved retention process.'));
    }

    protected function casts(): array
    {
        return ['checkout_url' => 'encrypted', 'expires_at' => 'datetime', 'provider_paid_at' => 'datetime', 'attempt_no' => 'integer'];
    }

    public function isLive(): bool
    {
        return in_array($this->status, [self::OPEN, self::PROCESSING], true);
    }

    /** @return BelongsTo<Funding, $this> */
    public function funding(): BelongsTo
    {
        return $this->belongsTo(Funding::class);
    }
}
