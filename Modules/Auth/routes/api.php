<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuditLogController;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\DemoLoginController;
use Modules\Auth\Http\Controllers\EmailVerificationController;
use Modules\Auth\Http\Controllers\PasswordResetController;
use Modules\Auth\Http\Controllers\TeacherRegistrationController;
use Modules\Auth\Http\Controllers\UserAdminController;

// Public endpoints: each proves itself some other way than a bearer token.
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/admin/login', fn (Request $request, AuthController $controller) => $controller->loginAsRole($request, 'admin'));
Route::post('/auth/teacher/login', fn (Request $request, AuthController $controller) => $controller->loginAsRole($request, 'teacher'));
Route::post('/auth/parent/login', fn (Request $request, AuthController $controller) => $controller->loginAsRole($request, 'parent'));
Route::post('/auth/student/login', fn (Request $request, AuthController $controller) => $controller->loginAsRole($request, 'student'));

Route::get('/auth/options', [TeacherRegistrationController::class, 'options']);
Route::post('/auth/register-teacher', [TeacherRegistrationController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/demo-login', [DemoLoginController::class, 'store'])->middleware('throttle:20,1');
Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');
// The signed link from the confirmation mail; the signature (48 h) is its proof.
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->name('center.email.verify')->middleware('throttle:30,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/password', [AuthController::class, 'changePassword']);
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:email-verification');

    // The school admin's accounts and their audit trail.
    Route::get('/users', [UserAdminController::class, 'index']);
    Route::post('/users', [UserAdminController::class, 'store']);
    Route::post('/users/{user}/approve', [UserAdminController::class, 'approve']);
    Route::delete('/users/{user}/reject', [UserAdminController::class, 'reject']);
    Route::post('/users/{user}/disable', [UserAdminController::class, 'disable']);
    Route::post('/users/{user}/enable', [UserAdminController::class, 'enable']);
    Route::patch('/users/{user}/role', [UserAdminController::class, 'role']);
    Route::post('/users/{user}/reset-password', [UserAdminController::class, 'resetPassword']);
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});
