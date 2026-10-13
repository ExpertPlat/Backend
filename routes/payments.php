<?php

use App\Http\Controllers\Api\Front\ExpertWorkController as EW;

use App\Http\Controllers\Api\Front\UserCommerceController as UC;

use App\Http\Controllers\Api\Admin\FinanceController;
use App\Http\Controllers\Api\Expert\StripeAccountController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\User\FundingController;
use App\Http\Middleware\SprintOneApi;
use Illuminate\Support\Facades\Route;

// Sprint 5 (contract: docs/sprint5/CONTRACT.md). Additive; existing /api paths are untouched; no new URI version.
// No Transfer, Payout, or "mark as paid" route exists anywhere: payment truth comes only from Stripe (DR-090).

// Stripe -> SolveIt. No Sanctum: the Stripe-Signature over the raw body is the authentication.
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])->middleware('throttle:payments-webhook')->name('webhooks.stripe');

$user = [SprintOneApi::class, 'auth:sanctum', 'regular-user', 'user.verified', 'abilities:user:access'];
$expert = [SprintOneApi::class, 'auth:sanctum', 'expert', 'abilities:expert:access', 'expert.verified'];

Route::prefix('v2/user/cases/{case}')->whereNumber('case')->name('v2.user.cases.')->middleware($user)->group(function (): void {
    Route::get('agreements/{agreement}/funding', [UC::class, 'agreementFunding'])->whereNumber('agreement')->middleware('throttle:payments-read')->name('agreements.funding.show');
    Route::post('agreements/{agreement}/funding', [UC::class, 'openFunding'])->whereNumber('agreement')->middleware('throttle:payments-write')->name('agreements.funding.store');
    Route::get('fundings/{funding}', [UC::class, 'funding'])->whereNumber('funding')->middleware('throttle:payments-read')->name('fundings.show');
    Route::post('fundings/{funding}/checkout', [UC::class, 'checkout'])->whereNumber('funding')->middleware('throttle:payments-write')->name('fundings.checkout');
    Route::post('fundings/{funding}/sync', [UC::class, 'sync'])->whereNumber('funding')->middleware('throttle:payments-sync')->name('fundings.sync');
    Route::get('fundings/{funding}/documents', [UC::class, 'documents'])->whereNumber('funding')->middleware('throttle:payments-read')->name('fundings.documents.index');
    Route::get('fundings/{funding}/documents/{financialDocument}', [FundingController::class, 'document'])->whereNumber(['funding', 'financialDocument'])->middleware('throttle:payments-read')->name('fundings.documents.show');
    Route::post('fundings/{funding}/refund-requests', [FundingController::class, 'requestRefund'])->whereNumber('funding')->middleware('throttle:payments-write')->name('fundings.refund-requests.store');
    Route::get('fundings/{funding}/refunds', [FundingController::class, 'refunds'])->whereNumber('funding')->middleware('throttle:payments-read')->name('fundings.refunds.index');
});

Route::prefix('expert')->name('expert.')->middleware($expert)->group(function (): void {
    Route::get('stripe/account', [EW::class, 'stripeAccount'])->middleware('throttle:payments-read')->name('stripe.account.show');
    Route::post('stripe/onboarding-link', [EW::class, 'stripeLink'])->middleware('throttle:payments-write')->name('stripe.onboarding-link');
    Route::post('stripe/onboarding/return', [EW::class, 'stripeSync'])->middleware('throttle:payments-sync')->name('stripe.onboarding.return');
    Route::post('stripe/account/sync', [EW::class, 'stripeSync'])->middleware('throttle:payments-sync')->name('stripe.account.sync');
    Route::get('agreements/{agreement}/funding', [EW::class, 'agreementFunding'])->whereNumber('agreement')->middleware('throttle:payments-read')->name('agreements.funding.show');
});

Route::prefix('admin/finance')->name('admin.finance.')->middleware([SprintOneApi::class, 'auth:sanctum', 'admin', 'abilities:admin:access'])->group(function (): void {
    Route::get('fundings', [\App\Http\Controllers\Api\Front\AdminController::class, 'fundings'])->middleware('throttle:payments-read')->name('fundings.index');
    Route::get('fundings/{funding}', [\App\Http\Controllers\Api\Front\AdminController::class, 'funding'])->whereNumber('funding')->middleware('throttle:payments-read')->name('fundings.show');
    Route::post('fundings/{funding}/refunds', [\App\Http\Controllers\Api\Front\AdminController::class, 'createRefund'])->whereNumber('funding')->middleware('throttle:payments-write')->name('fundings.refunds.store');
    Route::post('fundings/{funding}/reconcile', [\App\Http\Controllers\Api\Front\AdminController::class, 'reconcile'])->whereNumber('funding')->middleware('throttle:payments-sync')->name('fundings.reconcile');
    Route::get('ledger', [\App\Http\Controllers\Api\Front\AdminController::class, 'ledger'])->middleware('throttle:payments-read')->name('ledger.index');
    Route::get('refunds', [\App\Http\Controllers\Api\Front\AdminController::class, 'refunds'])->middleware('throttle:payments-read')->name('refunds.index');
    Route::post('refunds/{refund}/decision', [\App\Http\Controllers\Api\Front\AdminController::class, 'decideRefund'])->whereNumber('refund')->middleware('throttle:payments-write')->name('refunds.decision');
    Route::get('reconciliation', [\App\Http\Controllers\Api\Front\AdminController::class, 'reconciliation'])->middleware('throttle:payments-read')->name('reconciliation.index');
    Route::get('disputes', [\App\Http\Controllers\Api\Front\AdminController::class, 'disputes'])->middleware('throttle:payments-read')->name('disputes.index');
    Route::get('stripe-accounts', [\App\Http\Controllers\Api\Front\AdminController::class, 'stripeAccounts'])->middleware('throttle:payments-read')->name('stripe-accounts.index');
});
