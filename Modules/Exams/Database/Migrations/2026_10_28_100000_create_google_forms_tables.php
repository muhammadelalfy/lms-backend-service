<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('exam_templates')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('form_id')->unique();
            $table->string('responder_uri');
            $table->json('question_map');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('google_form_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_form_id')->constrained('google_forms')->cascadeOnDelete();
            $table->string('response_id');
            $table->string('respondent_name')->nullable();
            $table->string('respondent_email')->nullable();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('score', 8, 2)->default(0);
            $table->decimal('max_score', 8, 2)->default(0);
            $table->json('answers');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['google_form_id', 'response_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_form_responses');
        Schema::dropIfExists('google_forms');
    }
};
