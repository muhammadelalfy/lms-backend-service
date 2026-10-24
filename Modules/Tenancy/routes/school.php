<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\BrandingController;

Route::get('branding', [BrandingController::class, 'show']);
Route::put('branding', [BrandingController::class, 'update'])->middleware('auth:sanctum');
