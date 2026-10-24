<?php

use Illuminate\Support\Facades\Route;
use Modules\Ai\Http\Controllers\MaterialController;

Route::middleware('auth:sanctum')->prefix('ai')->group(function () {
    Route::get('materials', [MaterialController::class, 'index']);
    Route::post('materials', [MaterialController::class, 'store']);
    Route::get('materials/{material}', [MaterialController::class, 'show']);
    Route::delete('materials/{material}', [MaterialController::class, 'destroy']);
    Route::post('materials/{material}/retry', [MaterialController::class, 'retry']);
    Route::get('materials/{material}/download', [MaterialController::class, 'download']);
});
