<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogVersion extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $v) {
            $v->forceFill(['domain_code' => $v->policy['domain'], 'specialty_code' => $v->policy['specialty'], 'service_code' => $v->policy['serviceType']]);
        });
        static::updating(function (self $v) {
            if ($v->isDirty(['entry_id', 'version', 'policy', 'policy_hash', 'domain_code', 'specialty_code', 'service_code', 'created_by'])) {
                throw new \LogicException('Catalog policy versions are immutable. Create a successor version.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Catalog versions are retained.'));
    }

    protected function casts(): array
    {
        return ['policy' => 'array', 'reviewed_at' => 'datetime', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<CatalogEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(CatalogEntry::class, 'entry_id');
    }
}
