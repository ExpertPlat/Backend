<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ephemeral lock marker; UNIQUE(expert_id, slot_start) prevents double booking. Deleted when a booking ends. */
class BookingSlotLock extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['slot_start' => 'datetime'];
    }
}
