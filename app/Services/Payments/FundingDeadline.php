<?php

namespace App\Services\Payments;

use App\Models\Agreement;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one authority for the funding deadline (DR-086). Never recompute this in controllers.
 *
 * No booking:                  basis + funding window (12 h).
 * Booking, lead >= min lead:   min(basis + 12 h, start - min lead (2 h)).
 * Booking, lead <  min lead:   immediate flow = earliest of start, hold expiry (if held) and anchor + immediate window (30 min),
 *                              where the anchor is the later of the funding basis and the booking's last change (so the deadline never slides).
 */
class FundingDeadline
{
    public const NO_BOOKING = 'no_booking';

    public const STANDARD = 'standard';

    public const CONSULTATION = 'consultation';

    public const IMMEDIATE = 'immediate';

    /** @return array{at: CarbonImmutable, rule: string} */
    public function compute(Agreement $agreement, CarbonInterface $basis): array
    {
        $basis = CarbonImmutable::instance($basis);
        $window = $basis->addHours((int) config('commerce.funding_window_hours'));
        $booking = Booking::where('agreement_id', $agreement->id)->whereIn('status', Booking::ACTIVE)->orderByDesc('id')->first();
        if ($booking === null) {
            return ['at' => $window, 'rule' => self::NO_BOOKING];
        }
        $start = CarbonImmutable::instance($booking->start_at);
        $minLead = (int) config('commerce.funding_min_lead_minutes');
        // The anchor is the later of the funding basis and the booking's last change; the lead is measured there, not at `now`, so the rule
        // (and therefore the deadline) is stable between evaluations and never slides.
        $anchor = $basis->greaterThan($booking->updated_at) ? $basis : CarbonImmutable::instance($booking->updated_at);
        if ($start->getTimestamp() - $anchor->getTimestamp() >= $minLead * 60) {
            $beforeConsultation = $start->subMinutes($minLead);

            return $beforeConsultation->lessThan($window)
                ? ['at' => $beforeConsultation, 'rule' => self::CONSULTATION]
                : ['at' => $window, 'rule' => self::STANDARD];
        }
        $candidates = [$start, $anchor->addMinutes((int) config('commerce.funding_immediate_window_minutes'))];
        if ($booking->status === Booking::HELD && $booking->hold_expires_at) {
            $candidates[] = CarbonImmutable::instance($booking->hold_expires_at);
        }
        usort($candidates, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return ['at' => $candidates[0], 'rule' => self::IMMEDIATE];
    }
}
