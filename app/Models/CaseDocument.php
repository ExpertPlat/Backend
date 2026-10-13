<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseDocument extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }

    /** @return BelongsTo<CaseDocumentVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CaseDocumentVersion::class, 'current_version_id');
    }

    /** @return HasMany<CaseDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CaseDocumentVersion::class, 'document_id');
    }
}
