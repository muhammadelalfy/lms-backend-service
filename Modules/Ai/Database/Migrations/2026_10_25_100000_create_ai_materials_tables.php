<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('original_name');
            $table->string('kind', 10); // pdf | docx | xlsx
            $table->string('mime', 120);
            $table->unsignedBigInteger('size_bytes');
            // Private disk path, centers/{school}/ai-materials/{uuid}; cleared when the file is deleted.
            $table->string('storage_path')->nullable();
            $table->string('sha256', 64);
            // uploaded -> extracting -> ready | failed | needs_ocr
            $table->string('status', 20)->default('uploaded')->index();
            $table->string('failure_reason', 40)->nullable();
            $table->unsignedInteger('pages_count')->nullable();
            $table->unsignedInteger('sheets_count')->nullable();
            $table->unsignedBigInteger('char_count')->nullable();
            $table->boolean('needs_text_review')->default(false);
            $table->string('excel_mode', 20)->default('auto');
            $table->unsignedInteger('ingest_version')->default(0);
            $table->string('embed_model', 60)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['owner_user_id', 'id']);
        });

        // The outline of a material (pickers, citations). The text itself lives in the AI service.
        Schema::create('ai_material_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('ai_materials')->cascadeOnDelete();
            $table->string('kind', 10); // page | sheet | section
            $table->string('label');
            $table->unsignedInteger('ordinal');
            $table->unsignedInteger('char_count')->default(0);
            $table->boolean('excluded')->default(false);
            $table->timestamps();
            $table->index(['material_id', 'ordinal']);
        });

        // Where the AI service put each chunk (reference only, no text).
        Schema::create('ai_material_chunk_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('ai_materials')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('ai_material_sections')->cascadeOnDelete();
            $table->string('chunk_ref', 60);
            $table->unsignedInteger('ordinal');
            $table->unsignedInteger('char_count')->default(0);
            $table->timestamps();
            $table->index(['material_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_material_chunk_refs');
        Schema::dropIfExists('ai_material_sections');
        Schema::dropIfExists('ai_materials');
    }
};
