<?php

namespace App\Models;

use App\Enums\ExpertRenewalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpertScopeRenewal extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['status' => ExpertRenewalStatus::class, 'version' => 'integer', 'feedback' => 'encrypted', 'history' => 'encrypted:array', 'review' => 'encrypted:array', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(ExpertVerifiedScope::class, 'scope_id');
    }

    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ExpertScopeRenewalSubmission::class, 'renewal_id')->orderBy('sequence');
    }
}
