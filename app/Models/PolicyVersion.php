<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class PolicyVersion extends Model
{
    use AppendOnly;

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['effective_at' => 'datetime', 'created_at' => 'datetime', 'requires_reconsent' => 'boolean', 'is_published' => 'boolean'];
    }
}
