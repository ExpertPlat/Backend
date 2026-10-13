<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class UserProfileVersion extends Model
{
    use AppendOnly;

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'changed_fields' => 'array', 'version' => 'integer', 'created_at' => 'datetime'];
    }
}
