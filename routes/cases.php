<?php

use App\Http\Controllers\Api\Admin\CaseOversightController;
use App\Http\Controllers\Api\User\Cases\CaseController;
use App\Http\Controllers\Api\User\Cases\CaseDocumentController;
use App\Http\Middleware\SprintOneApi;
use Illuminate\Support\Facades\Route;

Route::prefix('user')->name('user.')->middleware([SprintOneApi::class, 'auth:sanctum', 'regular-user', 'user.verified', 'abilities:user:access'])->group(function (): void {
    Route::get('case-intake/bootstrap', [CaseController::class, 'bootstrap'])->middleware('throttle:cases-read')->name('case-intake.bootstrap');
    Route::prefix('cases')->name('cases.')->group(function (): void {
        Route::get('', [CaseController::class, 'index'])->middleware('throttle:cases-read')->name('index');
        Route::post('', [CaseController::class, 'store'])->middleware('throttle:cases-create')->name('store');
        Route::get('{case}', [CaseController::class, 'show'])->whereNumber('case')->middleware('throttle:cases-read')->name('show');
        Route::patch('{case}', [CaseController::class, 'update'])->whereNumber('case')->middleware('throttle:cases-autosave')->name('update');
        foreach (['cancel' => ['cancel', 'write'], 'intake-assessments' => ['assess', 'assessment'], 'intake-confirmation' => ['confirm', 'write'], 'submit' => ['submit', 'submit']] as $path => [$action,$limit]) {
            Route::post('{case}/'.$path, [CaseController::class, $action])->whereNumber('case')->middleware('throttle:cases-'.$limit)->name(in_array($path, ['cancel', 'submit']) ? $path : $path.'.store');
        }
        Route::post('{case}/context-snapshots', [CaseController::class, 'attach'])->whereNumber('case')->middleware('throttle:cases-write')->name('context-snapshots.store');
        Route::delete('{case}/context-snapshots/{snapshot}', [CaseController::class, 'detach'])->whereNumber(['case', 'snapshot'])->middleware('throttle:cases-write')->name('context-snapshots.destroy');
        foreach (['timeline', 'clarifications'] as $action) {
            Route::get('{case}/'.$action, [CaseController::class, $action])->whereNumber('case')->middleware('throttle:cases-read')->name($action.'.index');
        }
        Route::get('{case}/documents', [\App\Http\Controllers\Api\Front\SharedController::class, 'documents'])->whereNumber('case')->middleware('throttle:cases-read')->name('documents.index');
        Route::post('{case}/documents', [\App\Http\Controllers\Api\Front\SharedController::class, 'upload'])->whereNumber('case')->middleware('throttle:cases-upload')->name('documents.store');
        Route::get('{case}/documents/{document}/versions', [CaseDocumentController::class, 'versions'])->whereNumber(['case', 'document'])->middleware('throttle:cases-read')->name('documents.versions.index');
        Route::post('{case}/documents/{document}/versions', [CaseDocumentController::class, 'replace'])->whereNumber(['case', 'document'])->middleware('throttle:cases-upload')->name('documents.versions.store');
        Route::delete('{case}/documents/{document}', [\App\Http\Controllers\Api\Front\SharedController::class, 'deleteDocument'])->whereNumber(['case', 'document'])->middleware('throttle:cases-write')->name('documents.destroy');
        foreach (['download', 'preview'] as $action) {
            Route::get('{case}/documents/{document}/versions/{version}/'.$action, [CaseDocumentController::class, $action])->whereNumber(['case', 'document', 'version'])->middleware('throttle:cases-download')->name('documents.versions.'.$action);
        }
    });
});
Route::prefix('admin/cases')->name('admin.cases.')->middleware([SprintOneApi::class, 'auth:sanctum', 'admin', 'abilities:admin:access', 'throttle:cases-read'])->group(function (): void {
    Route::get('', [CaseOversightController::class, 'index'])->name('index');
    Route::get('{case}', [CaseOversightController::class, 'show'])->whereNumber('case')->name('show');
});
