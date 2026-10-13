<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatchingRun extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['case_version' => 'integer', 'catalog_revision' => 'integer', 'inputs' => 'array', 'eligible_count' => 'integer', 'excluded_count' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return HasMany<MatchingRunCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(MatchingRunCandidate::class, 'run_id');
    }

    /** @return BelongsTo<CaseServiceScope, $this> */
    public function scope(): BelongsTo
    {
        return $this->belongsTo(CaseServiceScope::class, 'scope_id');
    }

    /** @return BelongsTo<CatalogVersion, $this> */
    public function catalogVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogVersion::class);
    }
}
