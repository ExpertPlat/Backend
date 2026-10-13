<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Container for immutable versions. Only lifecycle columns change; content lives in {@see ProposalVersion}. */
class Proposal extends Model
{
    public const SUBMITTED = 'submitted';

    public const CHANGE_REQUESTED = 'change_requested';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const EXPIRED = 'expired';

    /** Statuses in which the Expert's capacity stays held and the proposal can still be decided. */
    public const LIVE = [self::SUBMITTED, self::CHANGE_REQUESTED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['current_version_no' => 'integer', 'version' => 'integer', 'valid_until' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    /** @return HasMany<ProposalVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProposalVersion::class)->orderBy('version_no');
    }

    /** @return HasMany<ProposalChangeRequest, $this> */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(ProposalChangeRequest::class)->orderBy('id');
    }

    /** @return HasMany<ProposalEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ProposalEvent::class)->orderBy('id');
    }

    /** @return HasOne<Agreement, $this> */
    public function agreement(): HasOne
    {
        return $this->hasOne(Agreement::class);
    }

    /** @return BelongsTo<CaseInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(CaseInvitation::class, 'invitation_id');
    }

    /** @return BelongsTo<Expert, $this> */
    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }

    /** @return BelongsTo<CaseRecord, $this> */
    public function caseRecord(): BelongsTo
    {
        return $this->belongsTo(CaseRecord::class, 'case_id');
    }
}
