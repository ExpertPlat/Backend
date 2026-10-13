<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Idempotency/audit record of one Stripe event. The payload is deliberately not stored. */
class StripeWebhookEvent extends Model
{
    public const RECEIVED = 'received';

    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    public const FAILED = 'failed';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['livemode' => 'boolean', 'attempts' => 'integer', 'processed_at' => 'datetime'];
    }
}
