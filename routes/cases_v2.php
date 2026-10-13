<?php

use App\Http\Controllers\Api\Admin\CaseV2OversightController;
use App\Http\Controllers\Api\Front\UserCaseController as F;
use App\Http\Controllers\Api\User\Cases\CaseV2Controller as C;
use App\Http\Middleware\SprintOneApi;
use Illuminate\Support\Facades\Route;

Route::prefix('v2/user/cases')->name('v2.user.cases.')->middleware([SprintOneApi::class, 'auth:sanctum', 'regular-user', 'user.verified', 'abilities:user:access'])->group(function () {
    // v7 frontend contract (App\Http\Controllers\Api\Front\UserCaseController).
    Route::get('', [F::class, 'index'])->middleware('throttle:cases-read')->name('index');
    Route::post('', [F::class, 'store'])->middleware('throttle:cases-create')->name('store');
    Route::get('{case}', [F::class, 'show'])->whereNumber('case')->middleware('throttle:cases-read')->name('show');
    Route::patch('{case}', [F::class, 'update'])->whereNumber('case')->middleware('throttle:cases-autosave')->name('update');
    Route::get('{case}/readiness', [C::class, 'readiness'])->whereNumber('case')->middleware('throttle:cases-read')->name('readiness');
    Route::put('{case}/scopes', [F::class, 'scopes'])->whereNumber('case')->middleware('throttle:cases-write')->name('scopes');
    Route::post('{case}/assessment', [F::class, 'assess'])->whereNumber('case')->middleware('throttle:ai-intake')->name('assessment');
    Route::get('{case}/ai-questions', [F::class, 'questions'])->whereNumber('case')->middleware('throttle:cases-read')->name('ai-questions.index');
    Route::post('{case}/ai-questions', [F::class, 'createQuestions'])->whereNumber('case')->middleware('throttle:cases-write')->name('ai-questions.store');
    Route::put('{case}/ai-questions/{set}/responses', [F::class, 'answer'])->whereNumber(['case', 'set'])->middleware('throttle:cases-write')->name('ai-questions.respond');
    Route::post('{case}/confirm', [F::class, 'confirm'])->whereNumber('case')->middleware('throttle:cases-write')->name('confirm');
    Route::post('{case}/submit', [F::class, 'submit'])->whereNumber('case')->middleware('throttle:cases-submit')->name('submit');
    Route::post('{case}/cancel', [F::class, 'cancel'])->whereNumber('case')->middleware('throttle:cases-write')->name('cancel');
});

Route::prefix('v2/admin/cases')->name('v2.admin.cases.')->middleware([SprintOneApi::class, 'auth:sanctum', 'admin', 'abilities:admin:access', 'throttle:cases-read'])->group(function () {
    Route::get('', [\App\Http\Controllers\Api\Front\AdminController::class, 'cases'])->name('index');
    Route::get('{case}', [\App\Http\Controllers\Api\Front\AdminController::class, 'caseDetail'])->whereNumber('case')->name('show');
});
