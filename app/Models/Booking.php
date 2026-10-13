<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Calendar reservation only. `confirmed` never means the work is funded or active. */
class Booking extends Model
{
    public const HELD = 'held';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const ACTIVE = [self::HELD, self::CONFIRMED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['start_at' => 'datetime', 'end_at' => 'datetime', 'hold_expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime', 'free_reschedule_granted_at' => 'datetime',
            'duration_minutes' => 'integer', 'reschedule_count' => 'integer', 'version' => 'integer'];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** One active booking per agreement through a unique index; null once terminal. */
    public static function activeKey(int $agreementId): string
    {
        return 'a'.$agreementId;
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** @return HasMany<BookingEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class)->orderBy('id');
    }
}
