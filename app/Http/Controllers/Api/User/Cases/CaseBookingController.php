<?php

namespace App\Http\Controllers\Api\User\Cases;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\CommerceRequest;
use App\Http\Resources\Commerce\CommercePresenter;
use App\Models\Agreement;
use App\Models\Booking;
use App\Services\Commerce\Agreements;
use App\Services\Commerce\Bookings;
use App\Services\Privacy\ActorIdempotency;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/** User-side agreements (read/cancel) and booking intent (availability, hold, confirm, cancel, reschedule). */
class CaseBookingController extends Controller
{
    use MatchingResponses;

    public function indexAgreements(CommerceRequest $r, int $case): JsonResponse
    {
        $row = $r->user()->cases()->findOrFail($case);
        $q = Agreement::where('case_id', $row->id)->orderByDesc('id');
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }

        return $this->page($q, $r->validated(), fn ($a) => CommercePresenter::agreement($a));
    }

    public function showAgreement(CommerceRequest $r, int $case, int $agreement): JsonResponse
    {
        $row = $r->user()->cases()->findOrFail($case);

        return $this->item(CommercePresenter::agreement(Agreement::where('case_id', $row->id)->findOrFail($agreement), true));
    }

    public function cancelAgreement(CommerceRequest $r, int $case, int $agreement, Agreements $agreements, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::agreement($agreements->cancelByUser($r->user(), $case, $agreement, $r->validated())), 'Agreement cancelled.'));
    }

    public function availability(CommerceRequest $r, int $case, int $agreement, Bookings $bookings): JsonResponse
    {
        $row = $r->user()->cases()->findOrFail($case);
        $a = Agreement::where('case_id', $row->id)->findOrFail($agreement);
        if (! in_array($a->status, [Agreement::AWAITING_PAYMENT, Agreement::FUNDED], true) || ! $a->consultation_minutes) {
            return response()->json(['status' => false, 'code' => $a->consultation_minutes ? 'AGREEMENT_NOT_BOOKABLE' : 'AGREEMENT_NO_SESSION', 'message' => 'This agreement cannot be booked.'], 409);
        }
        $from = CarbonImmutable::parse($r->validated('from'), 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($r->validated('to'), 'UTC')->endOfDay();
        $tz = CommercePresenter::timezoneOf($r->user());

        return $this->items(array_map(fn (CarbonImmutable $s) => ['startAt' => CommercePresenter::utc($s), 'endAt' => CommercePresenter::utc($s->addMinutes($a->consultation_minutes)),
            'presentation' => ['timezone' => $tz, 'startLocal' => $s->setTimezone($tz)->format('Y-m-d\TH:i:sP')]], $bookings->freeSlots($a, $from, $to)));
    }

    public function indexBookings(CommerceRequest $r, int $case, int $agreement): JsonResponse
    {
        $row = $r->user()->cases()->findOrFail($case);
        $a = Agreement::where('case_id', $row->id)->findOrFail($agreement);

        return $this->page(Booking::where('agreement_id', $a->id)->orderByDesc('id'), $r->validated(), fn ($b) => CommercePresenter::booking($b, $r->user()));
    }

    public function showBooking(CommerceRequest $r, int $case, int $booking): JsonResponse
    {
        $row = $r->user()->cases()->findOrFail($case);

        return $this->item(CommercePresenter::booking(Booking::where('case_id', $row->id)->findOrFail($booking), $r->user()));
    }

    public function hold(CommerceRequest $r, int $case, int $agreement, Bookings $bookings, ActorIdempotency $idempotency): JsonResponse
    {
        return $bookings->auditFailures($r->user(), 'agreement', $agreement, fn () => $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::booking($bookings->hold($r->user(), $case, $agreement, $r->validated('startAt')), $r->user()), 'Slot held.', 201)));
    }

    public function confirm(CommerceRequest $r, int $case, int $booking, Bookings $bookings, ActorIdempotency $idempotency): JsonResponse
    {
        return $bookings->auditFailures($r->user(), 'booking', $booking, fn () => $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::booking($bookings->confirm($r->user(), $case, $booking, (int) $r->validated('expectedVersion')), $r->user()), 'Booking confirmed. Payment is not yet available.')));
    }

    public function cancelBooking(CommerceRequest $r, int $case, int $booking, Bookings $bookings, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::booking($bookings->cancelByUser($r->user(), $case, $booking, $r->validated()), $r->user()), 'Booking cancelled.'));
    }

    public function reschedule(CommerceRequest $r, int $case, int $booking, Bookings $bookings, ActorIdempotency $idempotency): JsonResponse
    {
        return $bookings->auditFailures($r->user(), 'booking', $booking, fn () => $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::booking($bookings->rescheduleByUser($r->user(), $case, $booking, $r->validated()), $r->user()), 'Booking rescheduled.')));
    }
}
