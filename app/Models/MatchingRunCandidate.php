<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchingRunCandidate extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['eligible' => 'boolean', 'rank' => 'integer', 'match_reasons' => 'array', 'exclusion_reasons' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<MatchingRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(MatchingRun::class, 'run_id');
    }

    /** @return BelongsTo<Expert, $this> */
    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }
}
