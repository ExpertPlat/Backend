<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpertOffering extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const PAUSED = 'paused';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['delivery_modes' => 'array', 'languages' => 'array', 'jurisdiction_codes' => 'array', 'version' => 'integer', 'published_at' => 'datetime', 'price_from_minor' => 'integer'];
    }

    /** @return BelongsTo<Expert, $this> */
    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }

    /** @return BelongsTo<CatalogEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(CatalogEntry::class, 'catalog_entry_id');
    }
}
