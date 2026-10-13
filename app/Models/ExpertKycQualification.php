<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExpertKycQualification extends Model
{
    protected $fillable = [
        'degree',
        'field',
        'institution',
        'graduation_year',
        'sort_order',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(ExpertKycApplication::class, 'application_id');
    }

    public function document(): HasOne
    {
        return $this->hasOne(ExpertKycDocument::class, 'qualification_id');
    }
}
