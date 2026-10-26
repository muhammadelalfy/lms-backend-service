<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\BrandingController;
use Modules\Tenancy\Http\Controllers\MyPlanController;
use Modules\Tenancy\Http\Controllers\OnboardingController;

Route::get('branding', [BrandingController::class, 'show']);
Route::put('branding', [BrandingController::class, 'update'])->middleware('auth:sanctum');
Route::post('branding/logo', [BrandingController::class, 'uploadLogo'])->middleware(['auth:sanctum', 'throttle:20,1']);
Route::delete('branding/logo', [BrandingController::class, 'deleteLogo'])->middleware('auth:sanctum');

// "باقتي": the center's own plan and usage, and a request for a bigger one (admin only).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('my-plan', [MyPlanController::class, 'show']);
    Route::post('my-plan/upgrade-request', [MyPlanController::class, 'upgrade'])->middleware('throttle:plan-upgrades');

    // The admin's welcome checklist and email-verification banner.
    Route::get('onboarding', [OnboardingController::class, 'show']);
    Route::post('onboarding/dismiss', [OnboardingController::class, 'dismiss']);
});
