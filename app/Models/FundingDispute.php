<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundingDispute extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'evidence_due_by' => 'datetime', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<Funding, $this> */
    public function funding(): BelongsTo
    {
        return $this->belongsTo(Funding::class);
    }
}
