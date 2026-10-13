<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyRecord extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['response_body' => 'encrypted:array', 'expires_at' => 'datetime', 'response_status' => 'integer'];
    }
}
