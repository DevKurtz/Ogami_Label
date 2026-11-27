<?php

use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\LabelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'throttle:60,1'])->group(function () {
    Route::get('/batches', [BatchController::class, 'index']);
    Route::get('/delete-status', [BatchController::class, 'deleteStatus']);
    Route::post('/analyze', [BatchController::class, 'analyze'])->middleware('throttle:10,1');
    Route::post('/batches', [BatchController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/batches/{batch}', [BatchController::class, 'show']);
    Route::get('/batches/{batch}/label-svgs', [LabelController::class, 'showBatch']);
    Route::put('/batches/{batch}/template', [BatchController::class, 'updateTemplate'])->middleware('throttle:20,1');
    Route::delete('/batches/{batch}', [BatchController::class, 'destroy'])->middleware('throttle:5,1');
    Route::get('/records/{record}', [BatchController::class, 'showRecord']);
    Route::delete('/records/{record}', [BatchController::class, 'destroyRecord'])->middleware('throttle:5,1');
    Route::get('/records/{record}/label.svg', [LabelController::class, 'show']);
});

