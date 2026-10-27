<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S71: where a submitted exam stands in teacher grading, and the result it produced. Additive and
 * nullable: a session of an exam without rubrics keeps `grading_state` null (nothing changes for it),
 * and results recorded by hand have no session. `score` stays the whole-number score the apps read;
 * `score_exact` keeps quarter marks from written-answer grading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->string('grading_state', 20)->nullable(); // pending_review | reviewed
        });
        Schema::table('exam_results', function (Blueprint $table) {
            $table->unsignedBigInteger('session_id')->nullable()->unique();
            $table->decimal('score_exact', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->dropUnique(['session_id']);
            $table->dropColumn(['session_id', 'score_exact']);
        });
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropColumn('grading_state');
        });
    }
};
