<?php

use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\LabelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/batches', [BatchController::class, 'index'])->middleware('throttle:label-read');
    Route::get('/delete-status', [BatchController::class, 'deleteStatus'])->middleware('throttle:label-read');
    Route::post('/analyze', [BatchController::class, 'analyze'])->middleware('throttle:label-import');
    Route::post('/batches', [BatchController::class, 'store'])->middleware('throttle:label-import');
    Route::get('/batches/{batch}', [BatchController::class, 'show'])->middleware('throttle:label-read');
    Route::get('/batches/{batch}/label-svgs', [LabelController::class, 'showBatch'])->middleware('throttle:label-read');
    Route::put('/batches/{batch}/template', [BatchController::class, 'updateTemplate'])->middleware('throttle:label-design');
    Route::delete('/batches/{batch}', [BatchController::class, 'destroy'])->middleware('throttle:label-delete');
    Route::get('/records/{record}', [BatchController::class, 'showRecord'])->middleware('throttle:label-read');
    Route::delete('/records/{record}', [BatchController::class, 'destroyRecord'])->middleware('throttle:label-delete');
    Route::get('/records/{record}/label.svg', [LabelController::class, 'show'])->middleware('throttle:label-read');
});

