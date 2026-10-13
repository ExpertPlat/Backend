<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationIssue extends Model
{
    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['detail' => 'array', 'detected_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<Funding, $this> */
    public function funding(): BelongsTo
    {
        return $this->belongsTo(Funding::class);
    }
}
