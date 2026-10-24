<?php

use Illuminate\Support\Facades\Route;
use Modules\Courses\Http\Controllers\CatalogController;
use Modules\Courses\Http\Controllers\CourseController;
use Modules\Courses\Http\Controllers\CourseLessonController;
use Modules\Courses\Http\Controllers\CourseSubjectController;
use Modules\Courses\Http\Controllers\MyCoursesController;

Route::middleware('auth:sanctum')->group(function () {
    // Running courses (admins, and teachers for the courses they teach).
    Route::get('courses', [CourseController::class, 'index']);
    Route::post('courses', [CourseController::class, 'store']);
    Route::get('courses/{course}', [CourseController::class, 'show']);
    Route::patch('courses/{course}', [CourseController::class, 'update']);
    Route::delete('courses/{course}', [CourseController::class, 'destroy']);
    Route::put('courses/{course}/instructors', [CourseController::class, 'instructors']);
    Route::post('courses/{course}/status', [CourseController::class, 'status']);
    Route::get('courses/{course}/enrollments', [CourseController::class, 'enrollments']);
    Route::post('courses/{course}/enrollments/{enrollment}/cancel', [CourseController::class, 'cancelEnrollment']);
    Route::get('courses/{course}/versions', [CourseController::class, 'versions']);

    Route::post('courses/{course}/subjects', [CourseSubjectController::class, 'store']);
    Route::put('courses/{course}/subjects/order', [CourseSubjectController::class, 'order']);
    Route::patch('course-subjects/{subject}', [CourseSubjectController::class, 'update']);
    Route::delete('course-subjects/{subject}', [CourseSubjectController::class, 'destroy']);
    Route::post('course-subjects/{subject}/status', [CourseSubjectController::class, 'status']);

    Route::post('course-subjects/{subject}/lessons', [CourseLessonController::class, 'store']);
    Route::put('course-subjects/{subject}/lessons/order', [CourseLessonController::class, 'order']);
    Route::patch('course-lessons/{lesson}', [CourseLessonController::class, 'update']);
    Route::delete('course-lessons/{lesson}', [CourseLessonController::class, 'destroy']);
    Route::post('course-lessons/{lesson}/status', [CourseLessonController::class, 'status']);

    // What the school offers, and a student's own courses.
    Route::get('catalog', [CatalogController::class, 'index']);
    Route::get('catalog/{slug}', [CatalogController::class, 'show']);
    Route::post('catalog/{slug}/enroll', [CatalogController::class, 'enroll']);
    Route::get('my-courses', [MyCoursesController::class, 'index']);
    Route::get('my-courses/{course}', [MyCoursesController::class, 'show']);
    Route::post('course-lessons/{lesson}/complete', [MyCoursesController::class, 'complete']);
});
