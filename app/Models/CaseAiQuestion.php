<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseAiQuestion extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['question' => 'encrypted', 'reason' => 'encrypted', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<CaseAiQuestionSet, $this> */
    public function set(): BelongsTo
    {
        return $this->belongsTo(CaseAiQuestionSet::class, 'set_id');
    }
}
