<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseServiceScope extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $s) {
            if ($s->getOriginal('submitted_at') && $s->isDirty(['case_id', 'catalog_version_id', 'delivery_mode', 'jurisdiction_codes', 'answers', 'confirmed', 'policy_snapshot', 'submitted_at', 'detached_at'])) {
                throw new \LogicException('Submitted scope snapshots are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Case scope history is retained.'));
    }

    protected function casts(): array
    {
        return ['jurisdiction_codes' => 'array', 'answers' => 'encrypted:array', 'confirmed' => 'boolean', 'reason_codes' => 'array', 'policy_snapshot' => 'encrypted:array', 'readiness_checked_at' => 'datetime', 'submitted_at' => 'datetime', 'detached_at' => 'datetime'];
    }

    /** @return BelongsTo<CatalogVersion, $this> */
    public function catalogVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogVersion::class);
    }
}
