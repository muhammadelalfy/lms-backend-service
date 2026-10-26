<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Central database: leads from the landing (custom plan) and upgrade requests from a center's admin.
    public function up(): void
    {
        Schema::create('plan_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);                     // custom | upgrade | lead
            $table->string('audience', 16)->nullable();     // solo | group | center
            $table->string('name', 120);
            $table->string('phone', 24)->nullable();        // required for custom; an upgrade may come from an admin with no phone
            $table->string('email', 190)->nullable();
            $table->string('org_name', 120)->nullable();
            $table->unsignedInteger('students_estimate')->nullable();
            $table->string('plan_slug', 24)->nullable();
            $table->text('message');
            $table->string('school_id', 40)->nullable();    // no foreign key: a request outlives a closed center
            $table->string('status', 16)->default('new');   // new | contacted | closed
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['phone', 'created_at']);
            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_requests');
    }
};
