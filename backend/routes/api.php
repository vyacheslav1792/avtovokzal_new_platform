<?php

use App\Http\Controllers\Api\AdminRefundController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\RefundController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('stations', [CatalogController::class, 'stations']);
    Route::get('stations/{awtcod}/destinations', [CatalogController::class, 'destinations'])->whereNumber('awtcod');
    Route::get('trips', [CatalogController::class, 'trips']);
    Route::get('trips/places', [CatalogController::class, 'places']);
    Route::get('citizenships', [CatalogController::class, 'citizenships']);

    Route::post('orders', [OrderController::class, 'store']);
    Route::post('orders/confirm', [OrderController::class, 'confirm']);
    Route::get('orders/{id}', [OrderController::class, 'show'])->whereUuid('id');
    Route::get('orders/{id}/print', [OrderController::class, 'printData'])->whereUuid('id');
    Route::get('orders/{id}/pdf', [OrderController::class, 'pdf'])->whereUuid('id');

    Route::post('refunds/preview', [RefundController::class, 'preview']);
    Route::post('refunds/confirm', [RefundController::class, 'confirm']);

    // Админский возврат (перенос ais/back_bilet.php); авторизация — на этапе
    // users, на прод без неё не открывать.
    Route::post('admin/refunds/inspect', [AdminRefundController::class, 'inspect']);
    Route::post('admin/refunds/confirm', [AdminRefundController::class, 'confirm']);
    Route::post('admin/refunds/repair', [AdminRefundController::class, 'repair']);
});
