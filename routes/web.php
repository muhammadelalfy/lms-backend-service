<?php

use App\Http\Controllers\AndroidDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing');
});

// The Android app the landing offers (files on the persistent storage volume; see App\Support\AndroidRelease).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/download/android', [AndroidDownloadController::class, 'download'])->name('download.android');
    Route::get('/download/android.json', [AndroidDownloadController::class, 'manifest'])->name('download.android.json');
});
