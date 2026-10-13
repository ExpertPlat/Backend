<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A User's request for changes. Only `status`/`resolved_by_version` move (open -> addressed). */
class ProposalChangeRequest extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['categories' => 'array', 'message' => 'encrypted', 'version_no' => 'integer', 'resolved_by_version' => 'integer'];
    }
}
