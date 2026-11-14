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
        Schema::create('playground_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('playground_prompt_version_id')->nullable();
            $table->foreign('playground_prompt_version_id')->references('id')->on('playground_prompt_versions')->nullOnDelete();
            $table->text('system_prompt');
            $table->text('input');
            $table->jsonb('input_flags');
            $table->decimal('temperature', 3, 2);
            $table->unsignedSmallInteger('max_tokens');
            $table->string('version_label', 160);
            $table->timestampsTz();
            $table->index('playground_prompt_version_id');
            $table->index('created_at');
        });

        DB::statement('ALTER TABLE playground_runs ADD CONSTRAINT playground_runs_max_tokens_check CHECK (max_tokens BETWEEN 16 AND 2048)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playground_runs');
    }
};
