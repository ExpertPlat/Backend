<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class CaseIntakeAssessment extends Model
{
    use AppendOnly;

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['result' => 'encrypted:array', 'created_at' => 'datetime'];
    }
}
