<?php

namespace App\Http\Controllers\Api\Expert;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\ExpertCommerceRequest;
use App\Http\Resources\Commerce\CommercePresenter;
use App\Models\Agreement;
use App\Models\Booking;
use App\Services\Commerce\Agreements;
use App\Services\Commerce\Bookings;
use App\Services\Privacy\ActorIdempotency;
use Illuminate\Http\JsonResponse;

/** Expert-side agreements (read/cancel) and bookings (read/cancel/reschedule). Experts never create holds. */
class BookingController extends Controller
{
    use MatchingResponses;

    public function indexAgreements(ExpertCommerceRequest $r): JsonResponse
    {
        $q = Agreement::where('expert_id', $r->expert()->id)->orderByDesc('id');
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }

        return $this->page($q, $r->validated(), fn ($a) => CommercePresenter::agreement($a, false, false));
    }

    public function showAgreement(ExpertCommerceRequest $r, int $agreement): JsonResponse
    {
        return $this->item(CommercePresenter::agreement(Agreement::where('expert_id', $r->expert()->id)->findOrFail($agreement), true, false));
    }

    public function cancelAgreement(ExpertCommerceRequest $r, int $agreement, Agreements $agreements, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::agreement($agreements->cancelByExpert($r->expert(), $agreement, $r->validated()), false, false), 'Agreement cancelled.'));
    }

    public function indexBookings(ExpertCommerceRequest $r): JsonResponse
    {
        // Held (unconfirmed) slots belong to the User's decision; the Expert sees confirmed and closed bookings only.
        $q = Booking::where('expert_id', $r->expert()->id)->where('status', '!=', Booking::HELD)->orderByDesc('id');
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }

        return $this->page($q, $r->validated(), fn ($b) => CommercePresenter::booking($b, $r->expert()));
    }

    public function showBooking(ExpertCommerceRequest $r, int $booking): JsonResponse
    {
        $b = Booking::where('expert_id', $r->expert()->id)->where('status', '!=', Booking::HELD)->findOrFail($booking);

        return $this->item(CommercePresenter::booking($b, $r->expert()));
    }

    public function cancelBooking(ExpertCommerceRequest $r, int $booking, Bookings $bookings, ActorIdempotency $idempotency): JsonResponse
    {
        return $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::booking($bookings->cancelByExpert($r->expert(), $booking, $r->validated()), $r->expert()), 'Booking cancelled.'));
    }

    public function reschedule(ExpertCommerceRequest $r, int $booking, Bookings $bookings, ActorIdempotency $idempotency): JsonResponse
    {
        return $bookings->auditFailures($r->expert(), 'booking', $booking, fn () => $idempotency->run($r, $r->validated(), fn () => $this->item(
            CommercePresenter::booking($bookings->rescheduleByExpert($r->expert(), $booking, $r->validated()), $r->expert()), 'Booking rescheduled.')));
    }
}
