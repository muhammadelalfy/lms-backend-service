<?php

use Illuminate\Support\Facades\Route;
use Modules\Ai\Http\Controllers\BlueprintController;
use Modules\Ai\Http\Controllers\MaterialController;

Route::middleware('auth:sanctum')->prefix('ai')->group(function () {
    Route::get('materials', [MaterialController::class, 'index']);
    Route::post('materials', [MaterialController::class, 'store']);
    Route::get('materials/{material}', [MaterialController::class, 'show']);
    Route::delete('materials/{material}', [MaterialController::class, 'destroy']);
    Route::post('materials/{material}/retry', [MaterialController::class, 'retry']);
    Route::get('materials/{material}/download', [MaterialController::class, 'download']);

    Route::get('blueprints', [BlueprintController::class, 'index']);
    Route::post('blueprints', [BlueprintController::class, 'store']);
    Route::get('blueprints/{blueprint}', [BlueprintController::class, 'show']);
    Route::put('blueprints/{blueprint}', [BlueprintController::class, 'update']);
    Route::delete('blueprints/{blueprint}', [BlueprintController::class, 'destroy']);
    Route::post('blueprints/{blueprint}/duplicate', [BlueprintController::class, 'duplicate']);
});
