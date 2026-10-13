<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Invoice (amount due) or receipt (amount paid). Not a tax invoice (DR-088). */
class FinancialDocument extends Model
{
    public const INVOICE = 'invoice';

    public const RECEIPT = 'receipt';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Financial records require an approved retention process.'));
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'issued_at' => 'datetime'];
    }

    /** @return BelongsTo<Funding, $this> */
    public function funding(): BelongsTo
    {
        return $this->belongsTo(Funding::class);
    }
}
