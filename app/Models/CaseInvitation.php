<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseInvitation extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const OPEN = [self::PENDING, self::ACCEPTED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'decline_note' => 'encrypted', 'message' => 'encrypted', 'expires_at' => 'datetime', 'expiring_notified_at' => 'datetime', 'responded_at' => 'datetime', 'accepted_at' => 'datetime', 'proposal_due_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Key enforcing one open invitation per (scope, expert) through a unique index. */
    public static function activeKey(int $scopeId, int $expertId): string
    {
        return $scopeId.':'.$expertId;
    }

    /** @return BelongsTo<CaseRecord, $this> */
    public function caseRecord(): BelongsTo
    {
        return $this->belongsTo(CaseRecord::class, 'case_id');
    }

    /** @return BelongsTo<CaseServiceScope, $this> */
    public function scope(): BelongsTo
    {
        return $this->belongsTo(CaseServiceScope::class, 'scope_id');
    }

    /** @return BelongsTo<Expert, $this> */
    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }

    /** @return BelongsTo<CaseBrief, $this> */
    public function brief(): BelongsTo
    {
        return $this->belongsTo(CaseBrief::class, 'brief_id');
    }

    /** @return BelongsTo<MatchingRunCandidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(MatchingRunCandidate::class, 'matching_candidate_id');
    }

    /** @return HasMany<CaseAccessGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(CaseAccessGrant::class, 'invitation_id');
    }
}
