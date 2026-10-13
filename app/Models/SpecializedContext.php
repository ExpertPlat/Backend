<?php

namespace App\Models;

use App\Enums\ContextStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SpecializedContext extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['status' => ContextStatus::class, 'current_version' => 'integer', 'allow_case_reuse' => 'boolean', 'archived_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SpecializedContextVersion::class, 'context_id');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(SpecializedContextVersion::class, 'context_id')->ofMany('version', 'max');
    }

    public function canUseInFutureCase(): bool
    {
        return $this->status === ContextStatus::Active
            && $this->allow_case_reuse
            && $this->latestVersion !== null
            && $this->latestVersion?->conflict_status !== 'unresolved'
            && in_array($this->country, config('countries', []), true)
            && array_key_exists($this->domain, config('context_schemas.domains', []));
    }
}
