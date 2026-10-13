<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Immutable Agreement Snapshot of an accepted Proposal version. Only the lifecycle columns below may change;
 * snapshot, amounts, actors and references never do. `awaiting_payment` is NOT a funded state.
 */
class Agreement extends Model
{
    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const CANCELLED = 'cancelled';

    /** Sprint 5: provider-confirmed funding. Set only by the payment confirmation path, never by a client. */
    public const FUNDED = 'funded';

    public const FUNDING_EXPIRED = 'funding_expired';

    private const MUTABLE = ['status', 'version', 'cancelled_at', 'cancel_reason_code', 'cancelled_by_type', 'cancelled_by_id', 'updated_at'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $a): void {
            // The snapshot is sealed exactly once at creation (hash 'pending' -> real hash); after that it is immutable.
            $sealing = $a->getOriginal('snapshot_hash') === 'pending' ? ['snapshot', 'snapshot_hash'] : [];
            if (array_diff(array_keys($a->getDirty()), self::MUTABLE, $sealing) !== []) {
                throw new LogicException('An Agreement Snapshot cannot be edited.');
            }
        });
        static::deleting(fn () => throw new LogicException('Agreements require an approved retention process.'));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'total_amount_minor' => 'integer', 'consultation_minutes' => 'integer', 'proposal_version_no' => 'integer', 'version' => 'integer',
            'accepted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return HasMany<AgreementMilestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(AgreementMilestone::class)->orderBy('sequence');
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class)->orderByDesc('id');
    }

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /** @return BelongsTo<Expert, $this> */
    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }
}
