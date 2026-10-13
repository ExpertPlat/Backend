<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class CaseBrief extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'brief_version' => 'integer', 'case_version' => 'integer', 'context_snapshot_ids' => 'array', 'document_version_ids' => 'array', 'approved_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
