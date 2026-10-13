<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One immutable commercial offer. Never updated or deleted. */
class ProposalVersion extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'version_no' => 'integer', 'total_amount_minor' => 'integer', 'consultation_minutes' => 'integer', 'valid_until' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return HasMany<ProposalMilestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(ProposalMilestone::class)->orderBy('sequence');
    }

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}
