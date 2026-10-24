<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Bumps whenever learner-visible content of a live course changes (see course_versions).
            $table->unsignedInteger('version')->default(1);
            // How long an enrollment gives access once active; null = no expiry.
            $table->unsignedSmallInteger('access_days')->nullable();
        });

        Schema::table('course_enrollments', function (Blueprint $table) {
            // pending -> active -> completed | cancelled | expired (the authority for course access).
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // The version the learner enrolled in, and the one they completed.
            $table->unsignedInteger('course_version')->default(1);
            $table->unsignedInteger('completed_version')->nullable();
        });

        Schema::create('course_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('reason', 60);
            // The outline and the rules that decide completion, as they were in this version.
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['course_id', 'number']);
        });

        // Enrollments made before this change: completed ones stay completed, free or paid ones are
        // active, and the rest wait for their payment.
        DB::table('course_enrollments')->orderBy('id')->each(function ($enrollment) {
            $paid = $enrollment->payment_id === null
                || DB::table('payments')->where('id', $enrollment->payment_id)->where('status', 'paid')->exists();
            $status = $enrollment->completed_at !== null ? 'completed' : ($paid ? 'active' : 'pending');
            DB::table('course_enrollments')->where('id', $enrollment->id)->update([
                'status' => $status,
                'activated_at' => $status === 'pending' ? null : $enrollment->created_at,
                'completed_version' => $status === 'completed' ? 1 : null,
            ]);
        });

        // Courses already published get their first version, so their history starts at what learners saw.
        DB::table('courses')->whereNotNull('published_at')->orderBy('id')->each(function ($course) {
            $subjects = DB::table('course_subjects')->where('course_id', $course->id)->orderBy('position')->get()->map(fn ($subject) => [
                'id' => $subject->id,
                'title' => $subject->title,
                'status' => $subject->status,
                'lessons' => DB::table('course_lessons')->where('subject_id', $subject->id)->orderBy('position')->get()
                    ->map(fn ($lesson) => ['id' => $lesson->id, 'title' => $lesson->title, 'status' => $lesson->status])->all(),
            ])->all();
            DB::table('course_versions')->insert([
                'course_id' => $course->id, 'number' => 1, 'reason' => 'initial',
                'snapshot' => json_encode(['outline' => $subjects, 'rules' => ['sequencing_mode' => $course->sequencing_mode, 'outline_style' => $course->outline_style]], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_versions');
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropColumn(['status', 'activated_at', 'expires_at', 'cancelled_at', 'course_version', 'completed_version']);
        });
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['version', 'access_days']);
        });
    }
};
