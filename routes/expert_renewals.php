<?php

use App\Http\Controllers\Api\Admin\ExpertRenewalController;
use App\Http\Controllers\Api\Expert\Renewal\RenewalController;
use App\Http\Middleware\SprintOneApi;
use Illuminate\Support\Facades\Route;

Route::prefix('expert')->name('expert.renewals.')->middleware([SprintOneApi::class, 'auth:sanctum', 'expert', 'abilities:expert:access', 'expert.verified', 'front:scopes,requests,request'])->group(function (): void {
    Route::get('scope-renewals/scopes', [RenewalController::class, 'scopes'])->middleware('throttle:privacy-read')->name('scopes');
    Route::post('scopes/{scope}/renewals', [RenewalController::class, 'store'])->whereNumber('scope')->middleware('throttle:expert-kyc-write')->name('store');
    Route::get('scope-renewals', [RenewalController::class, 'index'])->middleware('throttle:privacy-read')->name('index');
    Route::get('scope-renewals/{renewal}', [RenewalController::class, 'show'])->whereNumber('renewal')->middleware('throttle:privacy-read')->name('show');
    foreach (['evidence', 'submit', 'cancel'] as $action) {
        Route::post('scope-renewals/{renewal}/'.$action, [RenewalController::class, $action])->whereNumber('renewal')->middleware($action === 'evidence' ? 'throttle:expert-kyc-upload' : 'throttle:expert-kyc-write')->name($action);
    }
    Route::get('scope-renewals/{renewal}/submissions/{submission}/document', [RenewalController::class, 'document'])->whereNumber(['renewal', 'submission'])->middleware('throttle:privacy-read')->name('document');
});
Route::prefix('admin/scope-renewals')->name('admin.renewals.')->middleware([SprintOneApi::class, 'auth:sanctum', 'admin', 'abilities:admin:access'])->group(function (): void {
    Route::get('', [\App\Http\Controllers\Api\Front\AdminController::class, 'renewals'])->middleware('throttle:privacy-read')->name('index');
    Route::get('{renewal}', [\App\Http\Controllers\Api\Front\AdminController::class, 'renewal'])->whereNumber('renewal')->middleware('throttle:privacy-read')->name('show');
    Route::get('{renewal}/submissions/{submission}/document', [ExpertRenewalController::class, 'document'])->whereNumber(['renewal', 'submission'])->middleware('throttle:privacy-read')->name('document');
    foreach (['start-review' => 'startReview', 'request-information' => 'requestInformation', 'reject' => 'reject', 'approve' => 'approve'] as $path => $action) {
        Route::post('{renewal}/'.$path, [\App\Http\Controllers\Api\Front\AdminController::class, 'decideRenewal'])->whereNumber('renewal')->middleware('throttle:admin-kyc-decisions')->name($path);
    }
});
