<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who decided what on a draft: edits, approvals, removals, regenerations, publishing.
        Schema::create('ai_draft_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('ai_draft_exams')->cascadeOnDelete();
            $table->unsignedBigInteger('question_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('details')->nullable(); // ids, field names and counts only, never question text
            $table->timestamp('created_at')->useCurrent();
            $table->index(['draft_id', 'id']);
        });

        // Model answer and marking scheme of a published exam question, kept beside `exam_questions`
        // (the exam runner never sees them). Also used later for manual exams (grading assistance).
        Schema::create('exam_question_rubrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->unique()->constrained('exam_questions')->cascadeOnDelete();
            $table->text('model_answer')->nullable();
            $table->json('rubric')->nullable();
            $table->string('source', 20)->default('manual'); // manual | ai_draft
            $table->unsignedBigInteger('ai_draft_question_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_question_rubrics');
        Schema::dropIfExists('ai_draft_events');
    }
};
