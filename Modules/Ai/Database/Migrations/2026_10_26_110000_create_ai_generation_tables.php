<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The draft exam a generation job fills; frozen once published into Exams.
        Schema::create('ai_draft_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 300);
            $table->string('status', 20)->default('draft')->index(); // draft | published
            $table->unsignedInteger('total_marks');
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->string('grade_level', 200)->nullable();
            $table->unsignedBigInteger('template_id')->nullable(); // the published ExamTemplate
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['owner_user_id', 'id']);
        });

        // One run of the AI: a whole draft (kind generate) or one replacement question (kind regenerate).
        Schema::create('ai_generation_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->default('generate');
            $table->foreignId('blueprint_id')->nullable()->constrained('exam_blueprints')->nullOnDelete();
            $table->json('blueprint_snapshot'); // frozen copy: later blueprint edits never change a job
            $table->foreignId('draft_id')->constrained('ai_draft_exams')->cascadeOnDelete();
            $table->unsignedBigInteger('target_question_id')->nullable(); // regenerate: the question replaced
            $table->string('instruction', 500)->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            // "{user id}:{Idempotency-Key}": one job per key, whatever the cache remembers.
            $table->string('idempotency_key', 120)->nullable()->unique();
            // queued -> retrieving -> generating -> verifying -> ready | needs_attention | failed | cancelled
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedInteger('estimate_units')->default(0);
            // Units held against the monthly cap while the job runs; spent units move to the ledger.
            $table->unsignedInteger('reserved_units')->default(0);
            $table->unsignedInteger('units_used')->default(0);
            $table->json('plan');               // batches of at most 10 questions: [[{type,count,marks}], ...]
            $table->unsignedInteger('batches_done')->default(0);
            $table->unsignedInteger('questions_requested')->default(0);
            $table->unsignedInteger('questions_generated')->default(0);
            $table->unsignedInteger('questions_verified')->default(0);
            $table->json('shortfall')->nullable();
            $table->boolean('partial')->default(false);
            $table->string('error_code', 40)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('model_generate', 120)->nullable();
            $table->string('model_verify', 120)->nullable();
            $table->json('prompt_versions')->nullable();
            $table->timestamps();
            $table->index(['draft_id', 'status']);
            $table->index(['requested_by', 'id']);
        });

        Schema::create('ai_draft_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('ai_draft_exams')->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('ai_generation_jobs')->nullOnDelete();
            $table->unsignedInteger('ordinal');
            $table->string('type', 20);
            $table->text('prompt');
            $table->json('options')->nullable();
            $table->text('correct_answer')->nullable();
            $table->text('explanation')->nullable();
            $table->text('model_answer')->nullable();
            $table->json('rubric')->nullable(); // {criteria: [{id, text, marks}], accept_alternatives: []}
            $table->unsignedInteger('marks');
            $table->string('difficulty', 10)->nullable();
            $table->string('bloom', 20)->nullable();
            $table->json('outcome_ids')->nullable();
            // Provenance: where in the teacher's material the question comes from.
            $table->unsignedBigInteger('source_material_id')->nullable();
            $table->string('source_chunk_ref', 60)->nullable();
            $table->string('source_label')->nullable();
            $table->text('source_quote')->nullable();
            $table->boolean('source_ocr')->default(false);
            $table->string('ai_ref', 40)->nullable();
            // The verification pass.
            $table->string('verify_verdict', 20)->nullable(); // supported | partial | unsupported; null = not verified
            $table->decimal('verify_confidence', 4, 3)->nullable();
            $table->json('verify_issues')->nullable();
            $table->text('verify_suggested_fix')->nullable();
            $table->unsignedBigInteger('duplicate_of')->nullable();
            $table->string('bank_match', 40)->nullable();
            $table->boolean('flagged')->default(false);
            // pending -> approved; removed / replaced rows stay as history.
            $table->string('state', 20)->default('pending');
            $table->string('origin', 20)->default('ai'); // ai | regenerated | manual
            $table->unsignedBigInteger('replaces_id')->nullable();
            $table->unsignedBigInteger('replaced_by_id')->nullable();
            $table->boolean('edited_by_teacher')->default(false);
            $table->string('prompt_version', 40)->nullable();
            $table->string('model_id', 120)->nullable();
            $table->timestamps();
            $table->index(['draft_id', 'state', 'ordinal']);
        });

        // Every AI unit spent, one row per AI call. `call_key` makes charging idempotent under retries.
        Schema::create('ai_usage_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // generate | regenerate | verify | grade
            $table->foreignId('job_id')->nullable()->constrained('ai_generation_jobs')->nullOnDelete();
            $table->unsignedBigInteger('draft_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('units');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('model_id', 120)->nullable();
            $table->string('prompt_version', 40)->nullable();
            $table->string('month', 7); // yyyy-mm
            $table->string('call_key', 120)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['month', 'kind']);
            $table->index(['month', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_ledger');
        Schema::dropIfExists('ai_draft_questions');
        Schema::dropIfExists('ai_generation_jobs');
        Schema::dropIfExists('ai_draft_exams');
    }
};
