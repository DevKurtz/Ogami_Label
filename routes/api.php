<?php

use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\LabelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'throttle:60,1'])->group(function () {
    Route::get('/batches', [BatchController::class, 'index']);
    Route::post('/batches', [BatchController::class, 'store'])->middleware('throttle:3,1');
    Route::get('/batches/{batch}', [BatchController::class, 'show']);
    Route::get('/records/{record}/label.svg', [LabelController::class, 'show']);
});
