<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseAiQuestionSet extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['case_version' => 'integer', 'input_summary' => 'array', 'latency_ms' => 'integer', 'completed_at' => 'datetime'];
    }

    /** @return HasMany<CaseAiQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(CaseAiQuestion::class, 'set_id')->orderBy('id');
    }

    /** @return BelongsTo<CaseRecord, $this> */
    public function caseRecord(): BelongsTo
    {
        return $this->belongsTo(CaseRecord::class, 'case_id');
    }
}
