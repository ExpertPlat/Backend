<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['contact_channels' => 'array', 'ai_assistance_enabled' => 'boolean', 'recording_preference' => 'boolean', 'version' => 'integer'];
    }
}
