<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Stripe Connect association of one Expert. Provider-safe metadata only; `stripe_account_id` is never serialised publicly. */
class StripeAccount extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['stripe_account_id'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Financial records require an approved retention process.'));
    }

    protected function casts(): array
    {
        return ['details_submitted' => 'boolean', 'charges_enabled' => 'boolean', 'payouts_enabled' => 'boolean', 'requirements_due' => 'array',
            'onboarding_started_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    /** @return BelongsTo<Expert, $this> */
    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }
}
