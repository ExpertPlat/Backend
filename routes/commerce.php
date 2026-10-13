<?php

use App\Http\Controllers\Api\Front\ExpertWorkController as EW;

use App\Http\Controllers\Api\Front\UserCommerceController as UC;

use App\Http\Controllers\Api\Expert\BookingController as ExpertBookingController;
use App\Http\Controllers\Api\Expert\ProposalController as ExpertProposalController;
use App\Http\Controllers\Api\User\Cases\CaseBookingController;
use App\Http\Controllers\Api\User\Cases\CaseProposalController;
use App\Http\Middleware\SprintOneApi;
use Illuminate\Support\Facades\Route;

// Sprint 4 (contract: docs/sprint4/CONTRACT.md). Existing /api paths are untouched; no new URI version is introduced.
// No payment, funding or delivery routes exist here: an accepted proposal is `awaiting_payment` and nothing more.

$user = [SprintOneApi::class, 'auth:sanctum', 'regular-user', 'user.verified', 'abilities:user:access'];
$expert = [SprintOneApi::class, 'auth:sanctum', 'expert', 'abilities:expert:access', 'expert.verified'];

Route::prefix('v2/user/cases/{case}')->whereNumber('case')->name('v2.user.cases.')->middleware($user)->group(function (): void {
    Route::get('proposals', [UC::class, 'proposals'])->middleware('throttle:commerce-read')->name('proposals.index');
    Route::get('proposals/{proposal}', [UC::class, 'proposal'])->whereNumber('proposal')->middleware('throttle:commerce-read')->name('proposals.show');
    Route::post('proposals/{proposal}/change-requests', [UC::class, 'requestChange'])->whereNumber('proposal')->middleware('throttle:commerce-write')->name('proposals.change-requests.store');
    Route::post('proposals/{proposal}/accept', [UC::class, 'accept'])->whereNumber('proposal')->middleware('throttle:commerce-write')->name('proposals.accept');
    Route::post('proposals/{proposal}/reject', [UC::class, 'reject'])->whereNumber('proposal')->middleware('throttle:commerce-write')->name('proposals.reject');

    Route::get('agreements', [UC::class, 'agreements'])->middleware('throttle:commerce-read')->name('agreements.index');
    Route::get('agreements/{agreement}', [UC::class, 'agreement'])->whereNumber('agreement')->middleware('throttle:commerce-read')->name('agreements.show');
    Route::post('agreements/{agreement}/cancel', [UC::class, 'cancelAgreement'])->whereNumber('agreement')->middleware('throttle:commerce-write')->name('agreements.cancel');
    Route::get('agreements/{agreement}/availability', [UC::class, 'availability'])->whereNumber('agreement')->middleware('throttle:commerce-read')->name('agreements.availability');
    Route::get('agreements/{agreement}/bookings', [UC::class, 'bookings'])->whereNumber('agreement')->middleware('throttle:commerce-read')->name('agreements.bookings.index');
    Route::post('agreements/{agreement}/bookings', [UC::class, 'hold'])->whereNumber('agreement')->middleware('throttle:commerce-write')->name('agreements.bookings.store');

    Route::get('bookings/{booking}', [UC::class, 'booking'])->whereNumber('booking')->middleware('throttle:commerce-read')->name('bookings.show');
    Route::post('bookings/{booking}/confirm', [UC::class, 'confirm'])->whereNumber('booking')->middleware('throttle:commerce-write')->name('bookings.confirm');
    Route::post('bookings/{booking}/cancel', [UC::class, 'cancelBooking'])->whereNumber('booking')->middleware('throttle:commerce-write')->name('bookings.cancel');
    Route::post('bookings/{booking}/reschedule', [UC::class, 'reschedule'])->whereNumber('booking')->middleware('throttle:commerce-write')->name('bookings.reschedule');
});

Route::prefix('expert')->name('expert.')->middleware($expert)->group(function (): void {
    Route::post('invitations/{invitation}/proposal', [EW::class, 'submitProposal'])->whereNumber('invitation')->middleware('throttle:commerce-write')->name('invitations.proposal.store');
    Route::get('proposals', [EW::class, 'proposals'])->middleware('throttle:commerce-read')->name('proposals.index');
    Route::get('proposals/{proposal}', [EW::class, 'proposal'])->whereNumber('proposal')->middleware('throttle:commerce-read')->name('proposals.show');
    Route::post('proposals/{proposal}/versions', [EW::class, 'revise'])->whereNumber('proposal')->middleware('throttle:commerce-write')->name('proposals.versions.store');
    Route::post('proposals/{proposal}/withdraw', [EW::class, 'withdraw'])->whereNumber('proposal')->middleware('throttle:commerce-write')->name('proposals.withdraw');

    Route::get('agreements', [EW::class, 'agreements'])->middleware('throttle:commerce-read')->name('agreements.index');
    Route::get('agreements/{agreement}', [EW::class, 'agreement'])->whereNumber('agreement')->middleware('throttle:commerce-read')->name('agreements.show');
    Route::post('agreements/{agreement}/cancel', [EW::class, 'cancelAgreement'])->whereNumber('agreement')->middleware('throttle:commerce-write')->name('agreements.cancel');

    Route::get('bookings', [EW::class, 'bookings'])->middleware('throttle:commerce-read')->name('bookings.index');
    Route::get('bookings/{booking}', [EW::class, 'booking'])->whereNumber('booking')->middleware('throttle:commerce-read')->name('bookings.show');
    Route::post('bookings/{booking}/cancel', [EW::class, 'cancelBooking'])->whereNumber('booking')->middleware('throttle:commerce-write')->name('bookings.cancel');
    Route::post('bookings/{booking}/reschedule', [EW::class, 'reschedule'])->whereNumber('booking')->middleware('throttle:commerce-write')->name('bookings.reschedule');
});
