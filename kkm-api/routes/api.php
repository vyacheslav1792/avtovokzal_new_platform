<?php

use App\Http\Controllers\Api\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(['client.auth', 'journal'])
    ->group(function () {
        Route::post('receipts', [ReceiptController::class, 'store'])->name('receipts.store');
        Route::get('receipts/{externalId}', [ReceiptController::class, 'show'])->name('receipts.show');
    });
