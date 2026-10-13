<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpertCatalogGrant extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $g) {
            if ($g->isDirty(['scope_id', 'catalog_version_id', 'jurisdiction_code', 'reviewed_by', 'evidence_type', 'evidence_id']) || ($g->getOriginal('revoked_at') && $g->isDirty('revoked_at'))) {
                throw new \LogicException('Reviewed grant history is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Reviewed grants are retained.'));
    }

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<ExpertVerifiedScope, $this> */
    public function scope(): BelongsTo
    {
        return $this->belongsTo(ExpertVerifiedScope::class, 'scope_id');
    }
}
