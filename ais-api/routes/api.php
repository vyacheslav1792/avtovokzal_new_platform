<?php

use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

// Контракт совместим с legacy apiv2 (SPA-рендер отчётов).
Route::prefix('v1')->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{slug}/meta', [ReportController::class, 'meta'])->name('reports.meta');
    Route::post('reports/{slug}/run', [ReportController::class, 'run'])->name('reports.run');
});
