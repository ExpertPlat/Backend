<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpertKycExperience extends Model
{
    protected $fillable = [
        'job_title',
        'organization',
        'from_month',
        'to_month',
        'is_current',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_current' => 'boolean'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ExpertKycApplication::class, 'application_id');
    }
}
