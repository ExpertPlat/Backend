<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class BookingEvent extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['from_start_at' => 'datetime', 'to_start_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
