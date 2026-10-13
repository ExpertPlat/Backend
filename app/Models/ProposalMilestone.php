<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class ProposalMilestone extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'amount_minor' => 'integer', 'due_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
