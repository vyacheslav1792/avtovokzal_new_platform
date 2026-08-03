<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('stations', [CatalogController::class, 'stations']);
    Route::get('stations/{awtcod}/destinations', [CatalogController::class, 'destinations'])->whereNumber('awtcod');
    Route::get('trips', [CatalogController::class, 'trips']);
    Route::get('trips/places', [CatalogController::class, 'places']);

    Route::post('orders', [OrderController::class, 'store']);
    Route::post('orders/confirm', [OrderController::class, 'confirm']);
    Route::get('orders/{id}', [OrderController::class, 'show'])->whereUuid('id');
});
