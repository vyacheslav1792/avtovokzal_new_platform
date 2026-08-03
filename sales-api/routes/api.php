<?php

use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\TripController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(['agent.auth', 'journal'])
    ->group(function () {
        Route::get('stations', [StationController::class, 'index'])->name('stations.index');
        Route::get('stations/{awtcod}/destinations', [StationController::class, 'destinations'])
            ->whereNumber('awtcod')->name('stations.destinations');

        Route::get('trips', [TripController::class, 'index'])->name('trips.index');
        Route::get('trips/places', [TripController::class, 'places'])->name('trips.places');
        Route::get('trips/status', [TripController::class, 'status'])->name('trips.status');

        Route::post('reservations', [ReservationController::class, 'store'])->name('reservations.store');
        Route::post('sales', [SaleController::class, 'store'])->name('sales.store');
        Route::post('refunds', [RefundController::class, 'store'])->name('refunds.store');
    });
