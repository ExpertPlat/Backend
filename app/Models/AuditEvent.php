<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use AppendOnly;

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }
}
