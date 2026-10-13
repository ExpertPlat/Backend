<?php

use App\Http\Controllers\Api\Catalog\CatalogController as C;
use App\Http\Controllers\Api\Front\SharedController as S;
use App\Http\Middleware\SprintOneApi;
use Illuminate\Support\Facades\Route;

Route::prefix('catalog')->name('catalog.')->middleware([SprintOneApi::class, 'throttle:catalog-read'])->group(function () {
    foreach (['jurisdictions' => 'jurisdiction', 'domains' => 'domain', 'services' => 'service'] as $path => $kind) {
        Route::get($path, [C::class, 'nodes'])->defaults('kind', $kind)->name($path);
    }
    // v7 frontend lists (App\Http\Controllers\Api\Front\SharedController).
    Route::get('countries', [S::class, 'countries'])->name('countries');
    Route::get('specialties', [S::class, 'specialties'])->name('specialties');
    Route::get('delivery-modes', [S::class, 'deliveryModes'])->name('delivery-modes');
    Route::get('entries', [C::class, 'entries'])->name('entries');
    Route::get('intake-schema/{entry}', [C::class, 'schema'])->whereNumber('entry')->name('schema');
});
Route::prefix('admin/catalog')->name('admin.catalog.')->middleware([SprintOneApi::class, 'auth:sanctum', 'admin', 'abilities:admin:access', 'throttle:catalog-admin'])->group(function () {
    Route::get('nodes', [C::class, 'nodes'])->name('nodes');
    Route::post('nodes', [\App\Http\Controllers\Api\Front\AdminController::class, 'createNode'])->name('node');
    Route::patch('nodes/{node}', [\App\Http\Controllers\Api\Front\AdminController::class, 'updateNode'])->whereNumber('node')->name('updateNode');
    Route::get('entries', [\App\Http\Controllers\Api\Front\AdminController::class, 'entries'])->name('entries');
    Route::post('entries', [\App\Http\Controllers\Api\Front\AdminController::class, 'createEntry'])->name('store');
    Route::get('entries/{entry}', [\App\Http\Controllers\Api\Front\AdminController::class, 'entry'])->whereNumber('entry')->name('detail');
    Route::post('entries/{entry}/versions', [\App\Http\Controllers\Api\Front\AdminController::class, 'createVersion'])->whereNumber('entry')->name('version');
    Route::get('versions/{version}/impact', [\App\Http\Controllers\Api\Front\AdminController::class, 'impact'])->whereNumber('version')->name('impact');
    foreach (['review', 'publish', 'pause'] as $a) {
        Route::post('versions/{version}/'.$a, [\App\Http\Controllers\Api\Front\AdminController::class, 'versionAction'])->whereNumber('version')->name($a);
    }
    Route::get('grants', [\App\Http\Controllers\Api\Front\AdminController::class, 'grants'])->name('grants');
    Route::post('grants', [\App\Http\Controllers\Api\Front\AdminController::class, 'createGrant'])->name('grant');
    Route::post('grants/{grant}/revoke', [\App\Http\Controllers\Api\Front\AdminController::class, 'revokeGrant'])->whereNumber('grant')->name('revoke');
    Route::get('waiting-cases', [\App\Http\Controllers\Api\Front\AdminController::class, 'waitingCases'])->name('waiting');
});
