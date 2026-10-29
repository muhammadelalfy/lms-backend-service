<?php

use Illuminate\Support\Facades\Route;
use Modules\Exams\Http\Controllers\ExamManagementController;
use Modules\Exams\Http\Controllers\ExamResultController;
use Modules\Exams\Http\Controllers\QuestionBankController;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('exams', ExamResultController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('/exams/{exam}/publish', [ExamResultController::class, 'publish']);
    Route::post('/exams/{exam}/correct', [ExamResultController::class, 'correct']);
    Route::get('/exams/{exam}/corrections', [ExamResultController::class, 'corrections']);
    Route::get('/exam-departments', [ExamManagementController::class, 'departments']);
    Route::post('/exam-departments', [ExamManagementController::class, 'storeDepartment']);
    Route::put('/exam-departments/{department}', [ExamManagementController::class, 'updateDepartment']);
    Route::delete('/exam-departments/{department}', [ExamManagementController::class, 'destroyDepartment']);
    Route::apiResource('question-bank', QuestionBankController::class)->only(['index', 'store', 'show', 'update', 'destroy'])->parameters(['question-bank' => 'questionBankQuestion']);
    Route::get('/exam-templates', [ExamManagementController::class, 'templates']);
    Route::post('/exam-templates', [ExamManagementController::class, 'storeTemplate']);
    Route::put('/exam-templates/{template}', [ExamManagementController::class, 'updateTemplate']);
    Route::delete('/exam-templates/{template}', [ExamManagementController::class, 'destroyTemplate']);
    Route::get('/exam-templates/{template}/pdf', [ExamManagementController::class, 'downloadPdf']);
    Route::post('/exam-templates/{template}/start', [ExamManagementController::class, 'startSession']);
    Route::post('/exam-sessions/{session}/events', [ExamManagementController::class, 'event']);
    Route::post('/exam-sessions/{session}/answers', [ExamManagementController::class, 'answer']);
    Route::post('/exam-sessions/{session}/answers/batch', [ExamManagementController::class, 'answers']);
    Route::post('/exam-sessions/{session}/submit', [ExamManagementController::class, 'submit']);
});
