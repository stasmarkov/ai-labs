<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pending_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source', 16);
            $table->string('tool', 120);
            $table->jsonb('arguments');
            $table->jsonb('preview');
            $table->string('status', 16)->index();
            $table->jsonb('result')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE pending_changes ADD CONSTRAINT pending_changes_source_check CHECK (source IN ('mcp'))");
        DB::statement("ALTER TABLE pending_changes ADD CONSTRAINT pending_changes_status_check CHECK (status IN ('pending', 'applying', 'confirmed', 'rejected', 'expired', 'failed'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_changes');
    }
};
