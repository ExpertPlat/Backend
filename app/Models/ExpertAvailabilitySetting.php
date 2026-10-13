<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'timezone',
    'service_modes',
    'weekly_schedule',
    'blackout_dates',
    'max_active_requests',
    'response_time_hours',
    'accepting_new_requests',
])]
class ExpertAvailabilitySetting extends Model
{
    protected function casts(): array
    {
        return [
            'service_modes' => 'array',
            'weekly_schedule' => 'array',
            'blackout_dates' => 'array',
            'max_active_requests' => 'integer',
            'response_time_hours' => 'integer',
            'accepting_new_requests' => 'boolean',
        ];
    }

    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }
}
