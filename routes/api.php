<?php

use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\LabelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'throttle:60,1'])->group(function () {
    Route::get('/batches', [BatchController::class, 'index']);
    Route::get('/delete-status', [BatchController::class, 'deleteStatus']);
    Route::post('/batches', [BatchController::class, 'store'])->middleware('throttle:3,1');
    Route::get('/batches/{batch}', [BatchController::class, 'show']);
    Route::delete('/batches/{batch}', [BatchController::class, 'destroy'])->middleware('throttle:5,1');
    Route::delete('/records/{record}', [BatchController::class, 'destroyRecord'])->middleware('throttle:5,1');
    Route::get('/records/{record}/label.svg', [LabelController::class, 'show']);
});
