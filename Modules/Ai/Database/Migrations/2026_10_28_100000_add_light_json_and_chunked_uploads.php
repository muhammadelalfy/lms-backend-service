<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_materials', function (Blueprint $table) {
            // size_bytes is what the plan counts: the upload's size while the original is kept, the light
            // (extracted text) size once it is removed. size_original is always what the teacher uploaded.
            $table->unsignedBigInteger('size_original')->nullable()->after('size_bytes');
            $table->boolean('original_removed')->default(false)->after('storage_path');
        });

        // A resumable upload in progress: the pieces are appended to one temp file on disk.
        Schema::create('ai_material_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('title')->nullable();
            $table->string('excel_mode', 20)->default('auto');
            $table->string('kind', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64)->nullable(); // of the whole file, optional, checked on complete
            $table->unsignedInteger('chunk_bytes');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('next_index')->default(0);
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->string('temp_path');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_material_uploads');
        Schema::table('ai_materials', function (Blueprint $table) {
            $table->dropColumn(['size_original', 'original_removed']);
        });
    }
};
