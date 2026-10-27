<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per written answer of a submitted session whose exam has rubrics: the item of the
        // teacher's grading queue, and the AI's suggestion for it. Never changed after it is ready.
        Schema::create('ai_grading_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('exam_questions')->cascadeOnDelete();
            $table->unsignedBigInteger('session_answer_id')->nullable(); // null: the question was left unanswered
            $table->unsignedBigInteger('template_id')->index();
            // queued -> ready | failed; skipped (no AI: no rubric, plan, cap); empty (blank answer, 0, no AI)
            $table->string('status', 20)->default('queued');
            $table->string('failure_code', 40)->nullable();
            $table->unsignedInteger('max_score');
            $table->decimal('suggested_score', 6, 2)->nullable();
            $table->json('criteria_scores')->nullable();
            $table->text('feedback')->nullable();  // to the student, formal Arabic
            $table->text('reasoning')->nullable(); // to the teacher
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('injection_suspected')->default(false);
            $table->boolean('needs_attention')->default(false);
            $table->string('prompt_version', 40)->nullable();
            $table->string('model_id', 120)->nullable();
            $table->unsignedInteger('units_used')->default(0);
            $table->timestamps();
            $table->unique(['session_id', 'question_id']);
            $table->index(['template_id', 'status']);
        });

        // The teacher's decision on one item: final score and feedback, who and when. Changes after the
        // result is published go through the audited correction of the exam result (S6).
        Schema::create('exam_answer_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suggestion_id')->unique()->constrained('ai_grading_suggestions')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('question_id');
            $table->unsignedBigInteger('session_answer_id')->nullable();
            $table->decimal('final_score', 6, 2);
            $table->text('final_feedback')->nullable();
            $table->string('decision', 20); // accepted | edited | overridden | manual
            $table->foreignId('decided_by')->constrained('users');
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_answer_grades');
        Schema::dropIfExists('ai_grading_suggestions');
    }
};
