<?php

namespace App\Models;

use App\Enums\ExpertKycActorType;
use App\Enums\ExpertKycApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpertKycStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => ExpertKycApplicationStatus::class,
            'to_status' => ExpertKycApplicationStatus::class,
            'actor_type' => ExpertKycActorType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ExpertKycApplication::class, 'application_id');
    }
}
