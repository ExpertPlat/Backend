<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'version' => 'integer'];
    }
}
