<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A teacher's standards for an AI exam. `is_template` rows are reusable standards templates,
        // visible to every teacher of the center unless `is_private`.
        Schema::create('exam_blueprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 300);
            $table->boolean('is_template')->default(false);
            $table->boolean('is_private')->default(false);
            $table->string('grade_level', 200)->nullable();
            $table->string('subject', 200)->nullable();
            $table->json('outcomes');            // [{id: "O1", text}]
            $table->json('difficulty_mix')->nullable(); // {easy, medium, hard} summing to 100; null = any
            $table->json('bloom_mix')->nullable();      // {remember, understand, ...} summing to 100; null = any
            $table->json('type_counts');         // {mcq: 10, essay: 2} in the teacher's order
            $table->json('marks_per_type');      // {mcq: 1, essay: 5} marks of ONE question of the type
            $table->unsignedInteger('total_marks');
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->string('language', 5)->default('ar');
            $table->json('source_selection');    // [{material_id, section_ids: [..] | null}]
            $table->text('instructions')->nullable();
            $table->text('avoid_topics')->nullable();
            $table->timestamps();
            $table->index(['owner_user_id', 'id']);
            $table->index(['is_template', 'is_private', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_blueprints');
    }
};
