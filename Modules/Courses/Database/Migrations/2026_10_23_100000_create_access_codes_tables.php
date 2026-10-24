<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_access_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['course_id', 'id']);
        });

        Schema::create('course_access_code_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_code_id')->constrained('course_access_codes')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('course_enrollments')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['access_code_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_access_code_redemptions');
        Schema::dropIfExists('course_access_codes');
    }
};
