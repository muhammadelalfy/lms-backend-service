<?php

use Illuminate\Support\Facades\Route;
use Modules\Ai\Http\Controllers\BlueprintController;
use Modules\Ai\Http\Controllers\DraftController;
use Modules\Ai\Http\Controllers\DraftReviewController;
use Modules\Ai\Http\Controllers\GenerationJobController;
use Modules\Ai\Http\Controllers\GradingController;
use Modules\Ai\Http\Controllers\MaterialController;
use Modules\Ai\Http\Controllers\QuestionRubricController;
use Modules\Ai\Http\Controllers\SessionResultController;
use Modules\Ai\Http\Controllers\UsageController;

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

    Route::post('generation-jobs/estimate', [GenerationJobController::class, 'estimate']);
    Route::post('generation-jobs', [GenerationJobController::class, 'store']);
    Route::get('generation-jobs/{job}', [GenerationJobController::class, 'show']);
    Route::post('generation-jobs/{job}/cancel', [GenerationJobController::class, 'cancel']);
    Route::post('generation-jobs/{job}/retry', [GenerationJobController::class, 'retry']);

    Route::get('usage', [UsageController::class, 'show']);

    Route::get('drafts', [DraftController::class, 'index']);
    Route::get('drafts/{draft}', [DraftController::class, 'show']);
    Route::get('drafts/{draft}/events', [DraftReviewController::class, 'events']);
    Route::post('drafts/{draft}/questions', [DraftReviewController::class, 'store']);
    Route::put('drafts/{draft}/questions/{question}', [DraftReviewController::class, 'update']);
    Route::delete('drafts/{draft}/questions/{question}', [DraftReviewController::class, 'destroy']);
    Route::post('drafts/{draft}/questions/{question}/approve', [DraftReviewController::class, 'approve']);
    Route::post('drafts/{draft}/questions/{question}/unapprove', [DraftReviewController::class, 'unapprove']);
    Route::post('drafts/{draft}/questions/{question}/regenerate', [DraftReviewController::class, 'regenerate']);
    Route::post('drafts/{draft}/approve-all-unflagged', [DraftReviewController::class, 'approveUnflagged']);
    Route::post('drafts/{draft}/reorder', [DraftReviewController::class, 'reorder']);
    Route::post('drafts/{draft}/publish', [DraftReviewController::class, 'publish']);

    Route::get('grading/queue', [GradingController::class, 'queue']);
    Route::post('grading/bulk-accept', [GradingController::class, 'bulkAccept']);
    Route::post('grading/{suggestion}/decide', [GradingController::class, 'decide'])->whereNumber('suggestion');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('exam-questions/{question}/rubric', [QuestionRubricController::class, 'show']);
    Route::put('exam-questions/{question}/rubric', [QuestionRubricController::class, 'update']);
    Route::get('exam-sessions/{session}/result', [SessionResultController::class, 'show']);
});
