<?php

use App\Http\Controllers\Api\Front\DevPaymentController;
use App\Http\Middleware\SprintOneApi;
use Illuminate\Support\Facades\Route;

// Local/testing only (loaded when PAYMENTS_DRIVER=fake in a local/testing environment). Never present in production.
Route::post('__mock/fundings/{funding}/complete', [DevPaymentController::class, 'complete'])->whereNumber('funding')
    ->middleware([SprintOneApi::class, 'auth:sanctum', 'regular-user', 'abilities:user:access', 'throttle:payments-write'])->name('dev.fundings.complete');
