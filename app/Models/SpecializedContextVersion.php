<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class SpecializedContextVersion extends Model
{
    use AppendOnly;

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'clarification' => 'encrypted', 'version' => 'integer', 'schema_version' => 'integer', 'created_at' => 'datetime'];
    }
}
