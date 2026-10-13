<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseAnalysis extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['summary' => 'encrypted', 'facts' => 'encrypted:array', 'scope_notes' => 'encrypted:array', 'questions' => 'encrypted:array',
            'answers' => 'encrypted:array', 'question_version' => 'integer', 'assessed_at' => 'datetime'];
    }

    /** @return BelongsTo<CaseRecord, $this> */
    public function caseRecord(): BelongsTo
    {
        return $this->belongsTo(CaseRecord::class, 'case_id');
    }
}
