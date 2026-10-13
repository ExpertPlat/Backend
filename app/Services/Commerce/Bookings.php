<?php

namespace App\Services\Commerce;

use App\Exceptions\PrivacyException;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\BookingSlotLock;
use App\Models\CaseInvitation;
use App\Models\CaseRecord;
use App\Models\Expert;
use App\Models\User;
use App\Notifications\Commerce\CommerceNotice;
use App\Services\Matching\MatchEligibility;
use App\Services\Payments\Fundings;
use App\Services\Privacy\AuditWriter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Booking intent / calendar reservation (docs/sprint4/CONTRACT.md section 5). A booking never funds or activates work.
 * Lock order everywhere: case, expert, agreement, booking.
 */
class Bookings
{
    use AuditsCommerceFailures;

    public function __construct(private MatchEligibility $eligibility, private AuditWriter $audit) {}

    public function hold(User $user, int $caseId, int $agreementId, string $startAt): Booking
    {
        return DB::transaction(function () use ($user, $caseId, $agreementId, $startAt): Booking {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            $agreement = $this->lockAgreementForCase($case, $agreementId);
            $this->assertBookable($agreement);
            $expert = Expert::query()->whereKey($agreement->expert_id)->firstOrFail();
            $this->releaseExpiredHolds($expert->id);
            if (Booking::where('agreement_id', $agreement->id)->whereIn('status', Booking::ACTIVE)->exists()) {
                throw new PrivacyException('BOOKING_ALREADY_ACTIVE', 'This agreement already has an active booking. Cancel or reschedule it instead.');
            }
            $this->assertEligible($expert, $agreement, $case);
            $start = $this->parse($startAt);
            $minutes = (int) $agreement->consultation_minutes;
            $this->assertSlot($expert, $start, $minutes);
            $booking = new Booking;
            try {
                $booking->forceFill(['agreement_id' => $agreement->id, 'case_id' => $case->id, 'expert_id' => $expert->id, 'user_id' => $user->id, 'status' => Booking::HELD, 'start_at' => $start,
                    'end_at' => $start->addMinutes($minutes), 'duration_minutes' => $minutes, 'hold_expires_at' => now()->addMinutes((int) config('commerce.hold_ttl_minutes')), 'version' => 1,
                    'active_key' => Booking::activeKey($agreement->id)])->save();
                $this->lockSlots($booking);
            } catch (UniqueConstraintViolationException $e) {
                throw $this->classifyUnique($e);
            }
            $this->event($booking, 'held', $user, null, $start);
            $this->audit->write($user, 'booking.held', 'booking', $booking->id, null, Booking::HELD, metadata: ['bookingId' => $booking->id, 'agreementId' => $agreement->id]);

            return $booking;
        });
    }

    public function confirm(User $user, int $caseId, int $bookingId, int $expectedVersion): Booking
    {
        $outcome = DB::transaction(function () use ($user, $caseId, $bookingId, $expectedVersion): Booking|string {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            [$booking, $agreement] = $this->lockBookingForCase($case, $bookingId);
            $this->assertVersion($booking, $expectedVersion);
            if ($booking->status !== Booking::HELD) {
                throw new PrivacyException('INVALID_BOOKING_STATE', 'Only a held slot can be confirmed.');
            }
            if ($booking->hold_expires_at->isPast()) {
                $this->expireRow($booking);

                return 'expired';
            }
            $this->assertBookable($agreement);
            $this->assertEligible(Expert::findOrFail($booking->expert_id), $agreement, $case);
            $booking->forceFill(['status' => Booking::CONFIRMED, 'confirmed_at' => now(), 'hold_expires_at' => null, 'version' => $booking->version + 1])->save();
            $this->event($booking, 'confirmed', $user, null, $booking->start_at);
            $this->audit->write($user, 'booking.confirmed', 'booking', $booking->id, Booking::HELD, Booking::CONFIRMED, metadata: ['bookingId' => $booking->id, 'agreementId' => $agreement->id]);
            Expert::find($booking->expert_id)?->notify(new CommerceNotice('booking.confirmed', ['bookingId' => $booking->id, 'agreementId' => $agreement->id]));

            return $booking;
        });
        if ($outcome === 'expired') {
            throw new PrivacyException('HOLD_EXPIRED', 'The slot hold expired. Choose a slot again.');
        }

        return $outcome;
    }

    public function cancelByUser(User $user, int $caseId, int $bookingId, array $d): Booking
    {
        return DB::transaction(function () use ($user, $caseId, $bookingId, $d): Booking {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            [$booking] = $this->lockBookingForCase($case, $bookingId);

            return $this->cancel($booking, $user, (int) $d['expectedVersion'], $d['reasonCode']);
        });
    }

    public function cancelByExpert(Expert $expert, int $bookingId, array $d): Booking
    {
        return DB::transaction(function () use ($expert, $bookingId, $d): Booking {
            [$booking] = $this->lockBookingForExpert($expert, $bookingId);
            $this->assertVisibleToExpert($booking);

            return $this->cancel($booking, $expert, (int) $d['expectedVersion'], $d['reasonCode']);
        });
    }

    public function rescheduleByUser(User $user, int $caseId, int $bookingId, array $d): Booking
    {
        return DB::transaction(function () use ($user, $caseId, $bookingId, $d): Booking {
            $case = $user->cases()->whereKey($caseId)->lockForUpdate()->firstOrFail();
            [$booking, $agreement] = $this->lockBookingForCase($case, $bookingId);

            return $this->reschedule($booking, $agreement, $user, $d, $case);
        });
    }

    public function rescheduleByExpert(Expert $expert, int $bookingId, array $d): Booking
    {
        return DB::transaction(function () use ($expert, $bookingId, $d): Booking {
            [$booking, $agreement] = $this->lockBookingForExpert($expert, $bookingId);
            $this->assertVisibleToExpert($booking);

            return $this->reschedule($booking, $agreement, $expert, $d, CaseRecord::findOrFail($booking->case_id));
        });
    }

    /** Agreement ended: the active booking (if any) is cancelled and its slots released. Caller holds the locks. */
    public function cancelActiveForAgreement(Agreement $agreement, User|Expert|null $actor, string $reason): void
    {
        foreach (Booking::where('agreement_id', $agreement->id)->whereIn('status', Booking::ACTIVE)->lockForUpdate()->get() as $booking) {
            $wasConfirmed = $booking->status === Booking::CONFIRMED;
            $this->close($booking, Booking::CANCELLED, $actor, $reason);
            if ($wasConfirmed) {
                $this->notifyOther($booking, $actor, 'booking.cancelled');
            }
        }
    }

    /** Scheduler entry point. Idempotent: only holds still `held` and past their expiry are touched. */
    public function expireDueHolds(): int
    {
        $expired = 0;
        foreach (Booking::where('status', Booking::HELD)->where('hold_expires_at', '<=', now())->orderBy('id')->pluck('id') as $id) {
            $expired += DB::transaction(function () use ($id): int {
                $expertId = Booking::whereKey($id)->value('expert_id');
                if ($expertId) {
                    Expert::query()->whereKey($expertId)->lockForUpdate()->first();
                }
                $booking = Booking::whereKey($id)->lockForUpdate()->first();
                if (! $booking || $booking->status !== Booking::HELD || $booking->hold_expires_at->isFuture()) {
                    return 0;
                }
                $this->expireRow($booking);

                return 1;
            });
        }

        return $expired;
    }

    /**
     * Free slot starts (UTC) for the agreement's session length. Read-only; the hold call re-validates everything.
     *
     * @return list<CarbonImmutable>
     */
    public function freeSlots(Agreement $agreement, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $expert = Expert::with('availability')->findOrFail($agreement->expert_id);
        $minutes = (int) $agreement->consultation_minutes;
        $step = (int) config('commerce.slot_granularity_minutes');
        $taken = BookingSlotLock::where('expert_id', $expert->id)->whereBetween('slot_start', [$from->subMinutes($minutes), $to->addDay()])->pluck('slot_start')
            ->map(fn ($t) => $t->utc()->format('Y-m-d H:i:s'))->flip();
        $heldExpired = Booking::where('expert_id', $expert->id)->where('status', Booking::HELD)->where('hold_expires_at', '<=', now())->pluck('id');
        $expiredSlots = $heldExpired->isEmpty() ? collect() : BookingSlotLock::whereIn('booking_id', $heldExpired)->pluck('slot_start')->map(fn ($t) => $t->utc()->format('Y-m-d H:i:s'))->flip();
        $earliest = now()->utc()->addMinutes((int) config('commerce.min_lead_minutes'));
        $latest = now()->utc()->addDays((int) config('commerce.max_horizon_days'));
        $out = [];
        $t = $from->utc();
        while ($t < $to && count($out) < (int) config('commerce.availability_max_slots')) {
            if ($t >= $earliest && $t <= $latest && $this->insideAvailability($expert, $t, $minutes)) {
                $free = true;
                for ($i = 0; $i < intdiv($minutes, $step); $i++) {
                    $k = $t->addMinutes($i * $step)->format('Y-m-d H:i:s');
                    if ($taken->has($k) && ! $expiredSlots->has($k)) {
                        $free = false;
                        break;
                    }
                }
                if ($free) {
                    $out[] = $t;
                }
            }
            $t = $t->addMinutes($step);
        }

        return $out;
    }

    private function reschedule(Booking $booking, Agreement $agreement, User|Expert $actor, array $d, CaseRecord $case): Booking
    {
        $this->assertVersion($booking, (int) $d['expectedVersion']);
        if ($booking->status !== Booking::CONFIRMED) {
            throw new PrivacyException('INVALID_BOOKING_STATE', 'Only a confirmed booking can be rescheduled.');
        }
        // Sprint 5 (DR-089): a granted free-reschedule waiver lets the User reschedule once even inside the notice window or past the limit.
        $waived = $actor instanceof User && $booking->free_reschedule_granted_at !== null && $booking->start_at->isFuture();
        if (! $waived && ($booking->start_at->timestamp - now()->timestamp < (int) config('commerce.reschedule_min_notice_hours') * 3600 || $booking->reschedule_count >= (int) config('commerce.max_reschedules'))) {
            throw new PrivacyException('RESCHEDULE_NOT_ALLOWED', 'This booking can no longer be rescheduled.');
        }
        $this->assertBookable($agreement);
        $expert = Expert::findOrFail($booking->expert_id);
        $this->releaseExpiredHolds($expert->id);
        $this->assertEligible($expert, $agreement, $case);
        $start = $this->parse($d['startAt']);
        $this->assertSlot($expert, $start, $booking->duration_minutes);
        $from = $booking->start_at;
        BookingSlotLock::where('booking_id', $booking->id)->delete();
        try {
            $booking->forceFill(['start_at' => $start, 'end_at' => $start->addMinutes($booking->duration_minutes), 'reschedule_count' => $booking->reschedule_count + 1, 'free_reschedule_granted_at' => null, 'version' => $booking->version + 1])->save();
            $this->lockSlots($booking);
        } catch (UniqueConstraintViolationException $e) {
            throw $this->classifyUnique($e); // the surrounding transaction rolls back: the original reservation stays intact
        }
        $this->event($booking, 'rescheduled', $actor, $from, $start);
        $this->audit->write($actor, 'booking.rescheduled', 'booking', $booking->id, Booking::CONFIRMED, Booking::CONFIRMED, metadata: ['bookingId' => $booking->id, 'agreementId' => $agreement->id]);
        $this->notifyOther($booking, $actor, 'booking.rescheduled');

        return $booking;
    }

    private function cancel(Booking $booking, User|Expert $actor, int $expectedVersion, string $reason): Booking
    {
        $this->assertVersion($booking, $expectedVersion);
        if (! $booking->isActive()) {
            throw new PrivacyException('INVALID_BOOKING_STATE', 'This booking is already closed.');
        }
        if ($booking->start_at->isPast()) {
            throw new PrivacyException('INVALID_BOOKING_STATE', 'A booking that has started cannot be cancelled here.');
        }
        $wasConfirmed = $booking->status === Booking::CONFIRMED;
        $this->close($booking, Booking::CANCELLED, $actor, $reason);
        if ($wasConfirmed) {
            $this->notifyOther($booking, $actor, 'booking.cancelled');
        }

        return $booking;
    }

    private function close(Booking $booking, string $status, User|Expert|null $actor, string $reason): void
    {
        $from = $booking->status;
        $booking->forceFill(['status' => $status, 'cancelled_at' => now(), 'cancel_reason_code' => $reason, 'cancelled_by_type' => $actor instanceof Expert ? 'expert' : ($actor ? 'user' : 'system'),
            'hold_expires_at' => null, 'active_key' => null, 'version' => $booking->version + 1])->save();
        BookingSlotLock::where('booking_id', $booking->id)->delete();
        $this->event($booking, 'cancelled', $actor, null, null, $reason);
        $this->audit->write($actor, 'booking.cancelled', 'booking', $booking->id, $from, $status, $reason, ['bookingId' => $booking->id, 'agreementId' => $booking->agreement_id]);
    }

    private function expireRow(Booking $booking): void
    {
        $booking->forceFill(['status' => Booking::EXPIRED, 'active_key' => null, 'version' => $booking->version + 1])->save();
        BookingSlotLock::where('booking_id', $booking->id)->delete();
        $this->event($booking, 'expired', null, null, null);
        $this->audit->write(null, 'booking.hold_expired', 'booking', $booking->id, Booking::HELD, Booking::EXPIRED, metadata: ['bookingId' => $booking->id, 'agreementId' => $booking->agreement_id]);
        User::find($booking->user_id)?->notify(new CommerceNotice('booking.hold_expired', ['bookingId' => $booking->id, 'agreementId' => $booking->agreement_id]));
    }

    /** Releases this Expert's lapsed holds so their slots become bookable immediately (caller holds the Expert lock). */
    private function releaseExpiredHolds(int $expertId): void
    {
        foreach (Booking::where('expert_id', $expertId)->where('status', Booking::HELD)->where('hold_expires_at', '<=', now())->lockForUpdate()->get() as $b) {
            $this->expireRow($b);
        }
    }

    private function lockSlots(Booking $booking): void
    {
        $step = (int) config('commerce.slot_granularity_minutes');
        $rows = [];
        for ($i = 0; $i < intdiv($booking->duration_minutes, $step); $i++) {
            $rows[] = ['expert_id' => $booking->expert_id, 'slot_start' => $booking->start_at->utc()->addMinutes($i * $step)->format('Y-m-d H:i:s'), 'booking_id' => $booking->id];
        }
        BookingSlotLock::insert($rows);
    }

    private function classifyUnique(UniqueConstraintViolationException $e): PrivacyException
    {
        return str_contains($e->getMessage(), 'active_key')
            ? new PrivacyException('BOOKING_ALREADY_ACTIVE', 'This agreement already has an active booking. Cancel or reschedule it instead.')
            : new PrivacyException('SLOT_UNAVAILABLE', 'That time is no longer available. Choose another slot.');
    }

    private function parse(string $startAt): CarbonImmutable
    {
        return CarbonImmutable::parse($startAt)->utc();
    }

    private function assertSlot(Expert $expert, CarbonImmutable $start, int $minutes): void
    {
        $step = (int) config('commerce.slot_granularity_minutes');
        if ($start->second !== 0 || $start->minute % $step !== 0 || $start < now()->addMinutes((int) config('commerce.min_lead_minutes')) || $start > now()->addDays((int) config('commerce.max_horizon_days'))) {
            throw new PrivacyException('SLOT_INVALID', 'The requested start time is not a valid bookable time.', 422);
        }
        if (! $this->insideAvailability(Expert::with('availability')->findOrFail($expert->id), $start, $minutes)) {
            throw new PrivacyException('SLOT_UNAVAILABLE', 'The expert is not available at that time.');
        }
    }

    /** The whole session must sit inside one enabled weekly window (Expert timezone, DST-correct) on a non-blackout date. */
    private function insideAvailability(Expert $expert, CarbonImmutable $start, int $minutes): bool
    {
        $a = $expert->availability;
        if (! $a) {
            return false;
        }
        $tz = $a->timezone ?: 'UTC';
        $ls = $start->setTimezone($tz);
        $le = $start->addMinutes($minutes)->setTimezone($tz);
        if ($ls->toDateString() !== $le->toDateString() || in_array($ls->toDateString(), $a->blackout_dates ?? [], true)) {
            return false;
        }
        $day = strtolower(substr($ls->format('D'), 0, 3));
        foreach ($a->weekly_schedule ?? [] as $d) {
            if (($d['day'] ?? null) !== $day || ! ($d['enabled'] ?? false)) {
                continue;
            }
            foreach ($d['windows'] ?? [] as $w) {
                [$sh, $sm] = array_map('intval', explode(':', $w['start']));
                [$eh, $em] = array_map('intval', explode(':', $w['end']));
                if ($ls >= $ls->setTime($sh, $sm) && $le <= $ls->setTime($eh, $em)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A held slot is the User's pending decision; an Expert can only act on a confirmed or closed booking. */
    private function assertVisibleToExpert(Booking $booking): void
    {
        if ($booking->status === Booking::HELD) {
            throw new PrivacyException('INVALID_BOOKING_STATE', 'This booking is not confirmed yet.');
        }
    }

    private function assertBookable(Agreement $agreement): void
    {
        if (! in_array($agreement->status, [Agreement::AWAITING_PAYMENT, Agreement::FUNDED], true)) {
            throw new PrivacyException('AGREEMENT_NOT_BOOKABLE', 'This agreement cannot be booked in its current state.');
        }
        if (! $agreement->consultation_minutes) {
            throw new PrivacyException('AGREEMENT_NO_SESSION', 'This agreement does not include a scheduled session.');
        }
    }

    public function assertEligible(Expert $expert, Agreement $agreement, CaseRecord $case): void
    {
        $inv = CaseInvitation::findOrFail($agreement->invitation_id);
        $scope = $case->serviceScopes()->with('catalogVersion.entry')->findOrFail($agreement->scope_id);
        $current = $this->eligibility->revalidate($expert, $scope->catalogVersion, $inv->jurisdiction_code, $scope->delivery_mode, $case->currentIntake->payload['language'] ?? null, true, $inv->id);
        if (! $current['eligible']) {
            throw new PrivacyException('ELIGIBILITY_CHANGED', 'The expert is no longer eligible for this service.');
        }
    }

    private function assertVersion(Booking $booking, int $expected): void
    {
        if ($booking->version !== $expected) {
            throw new PrivacyException('VERSION_CONFLICT', 'The booking has changed. Refresh before retrying.');
        }
    }

    private function lockAgreementForCase(CaseRecord $case, int $agreementId): Agreement
    {
        Expert::query()->whereKey(Agreement::where('case_id', $case->id)->whereKey($agreementId)->value('expert_id') ?? 0)->lockForUpdate()->first();

        return Agreement::where('case_id', $case->id)->whereKey($agreementId)->lockForUpdate()->firstOrFail();
    }

    /** @return array{0: Booking, 1: Agreement} */
    private function lockBookingForCase(CaseRecord $case, int $bookingId): array
    {
        $agreementId = Booking::where('case_id', $case->id)->whereKey($bookingId)->value('agreement_id') ?? 0;
        $agreement = $this->lockAgreementForCase($case, $agreementId);

        return [Booking::where('case_id', $case->id)->whereKey($bookingId)->lockForUpdate()->firstOrFail(), $agreement];
    }

    /** @return array{0: Booking, 1: Agreement} */
    private function lockBookingForExpert(Expert $expert, int $bookingId): array
    {
        Expert::query()->whereKey($expert->id)->lockForUpdate()->firstOrFail();
        $agreementId = Booking::where('expert_id', $expert->id)->whereKey($bookingId)->value('agreement_id') ?? 0;
        $agreement = Agreement::where('expert_id', $expert->id)->whereKey($agreementId)->lockForUpdate()->firstOrFail();

        return [Booking::where('expert_id', $expert->id)->whereKey($bookingId)->lockForUpdate()->firstOrFail(), $agreement];
    }

    private function notifyOther(Booking $booking, User|Expert|null $actor, string $type): void
    {
        if ($actor instanceof Expert) {
            User::find($booking->user_id)?->notify(new CommerceNotice($type, ['bookingId' => $booking->id, 'agreementId' => $booking->agreement_id, 'caseId' => $booking->case_id]));
        } else {
            Expert::find($booking->expert_id)?->notify(new CommerceNotice($type, ['bookingId' => $booking->id, 'agreementId' => $booking->agreement_id]));
        }
    }

    private function event(Booking $booking, string $type, User|Expert|null $actor, ?CarbonInterface $from, ?CarbonInterface $to, ?string $reason = null): void
    {
        $e = new BookingEvent;
        $e->forceFill(['booking_id' => $booking->id, 'type' => $type, 'actor_type' => match (true) {
            $actor instanceof User => 'user', $actor instanceof Expert => 'expert', default => 'system',
        }, 'actor_id' => $actor?->getKey(), 'from_start_at' => $from, 'to_start_at' => $to, 'reason_code' => $reason, 'created_at' => now()])->save();
        // Sprint 5: an unfunded Funding's deadline follows the booking (DR-086). Never expires anything here.
        if ($agreement = Agreement::find($booking->agreement_id)) {
            app(Fundings::class)->refreshDeadline($agreement);
        }
    }
}
