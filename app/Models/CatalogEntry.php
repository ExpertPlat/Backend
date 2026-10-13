<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogEntry extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [];
    }

    /** @return HasMany<CatalogVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CatalogVersion::class, 'entry_id');
    }
}
