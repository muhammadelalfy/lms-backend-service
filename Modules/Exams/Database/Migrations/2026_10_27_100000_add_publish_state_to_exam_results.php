<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S6: an exam result is draft -> published -> corrected. Additive only: the column default stays
 * `published` so code from the previous release (which treats every recorded result as final and
 * visible) keeps its meaning while it still runs; new code sets the state explicitly. Every existing
 * row is backfilled to published, since today it is already shown to the student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->string('status', 20)->default('published');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('last_corrected_at')->nullable();
            $table->index(['student_id', 'status']);
        });

        DB::table('exam_results')->whereNull('published_at')->update([
            'status' => 'published',
            'published_at' => DB::raw('created_at'),
        ]);

        // One row per changed field of an audited correction; never updated or deleted by the app.
        Schema::create('exam_result_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_result_id')->constrained('exam_results')->cascadeOnDelete();
            $table->uuid('correction_group_id')->index();
            $table->string('field', 20);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason');
            $table->foreignId('corrected_by')->constrained('users');
            $table->timestamp('corrected_at');
            $table->index(['exam_result_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_result_corrections');
        Schema::table('exam_results', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'status']);
            $table->dropColumn(['status', 'published_at', 'published_by', 'last_corrected_at']);
        });
    }
};
