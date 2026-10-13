<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only financial ledger entry. Never carries Case content; provider references are opaque Stripe ids. */
class LedgerEvent extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $table = 'financial_ledger_events';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['gross_amount_minor' => 'integer', 'amount_minor' => 'integer', 'platform_commission_minor' => 'integer', 'expert_provisional_minor' => 'integer',
            'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Funding, $this> */
    public function funding(): BelongsTo
    {
        return $this->belongsTo(Funding::class);
    }
}
