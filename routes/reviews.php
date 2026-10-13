<?php

use App\Http\Controllers\Api\Front\UserCommerceController as UC;

use App\Http\Controllers\Api\Admin\ExpertReviewController as AdminExpertReviewController;
use App\Http\Controllers\Api\PublicApi\ExpertReviewController as PublicExpertReviewController;
use App\Http\Controllers\Api\User\ReviewController;
use App\Http\Middleware\SprintOneApi;
use Illuminate\Support\Facades\Route;

// Sprint 4.1 (contract: docs/sprint4/reviews-contract.md). Additive; existing /api paths are untouched; no new URI version.

Route::get('/experts/{slug}/reviews', [\App\Http\Controllers\Api\Front\UserAccountController::class, 'expertReviews'])
    ->middleware([SprintOneApi::class, 'throttle:public-expert-profile'])->name('experts.reviews.index');

$user = [SprintOneApi::class, 'auth:sanctum', 'regular-user', 'user.verified', 'abilities:user:access'];

Route::prefix('v2/user')->name('v2.user.')->middleware($user)->group(function (): void {
    Route::get('cases/{case}/agreements/{agreement}/review-eligibility', [UC::class, 'reviewEligibility'])->whereNumber(['case', 'agreement'])->middleware('throttle:commerce-read')->name('reviews.eligibility');
    Route::post('cases/{case}/agreements/{agreement}/review', [UC::class, 'review'])->whereNumber(['case', 'agreement'])->middleware('throttle:commerce-write')->name('reviews.store');
    Route::put('reviews/{review}', [ReviewController::class, 'update'])->whereNumber('review')->middleware('throttle:commerce-write')->name('reviews.update');
});

Route::prefix('admin/expert-reviews')->name('admin.expert-reviews.')->middleware([SprintOneApi::class, 'auth:sanctum', 'admin', 'abilities:admin:access', 'throttle:catalog-admin'])->group(function (): void {
    Route::get('', [\App\Http\Controllers\Api\Front\AdminController::class, 'reviews'])->name('index');
    Route::post('{review}/moderation', [\App\Http\Controllers\Api\Front\AdminController::class, 'moderate'])->whereNumber('review')->name('moderate');
});
