<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataRightsRequestItem extends Model
{
    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['retain_until' => 'datetime', 'review_at' => 'datetime'];
    }
}
