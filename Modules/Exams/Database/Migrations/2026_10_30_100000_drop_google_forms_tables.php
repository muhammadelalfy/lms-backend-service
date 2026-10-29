<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** The Google Forms feature was removed; the tables its migration created are dropped (responses first). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('google_form_responses');
        Schema::dropIfExists('google_forms');
    }

    public function down(): void
    {
        // Intentionally empty: the feature no longer exists.
    }
};
