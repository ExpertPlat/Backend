<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class ExpertScopeRenewalSubmission extends Model
{
    use AppendOnly;

    protected $guarded = ['*'];

    protected $hidden = ['disk', 'path', 'checksum'];

    protected function casts(): array
    {
        return ['evidence' => 'encrypted:array'];
    }
}
