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
        Schema::create('playground_prompt_versions', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs()->primary();
            $table->bigInteger('playground_prompt_id');
            $table->foreign('playground_prompt_id')->references('id')->on('playground_prompts')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('system_prompt');
            $table->string('note', 200)->nullable();
            $table->decimal('temperature', 3, 2)->default(0.2);
            $table->unsignedSmallInteger('max_tokens')->default(512);
            $table->timestampTz('created_at')->nullable();
            $table->unique(['playground_prompt_id', 'version']);
        });

        DB::statement('ALTER TABLE playground_prompt_versions ADD CONSTRAINT playground_prompt_versions_version_check CHECK (version >= 1)');
        DB::statement('ALTER TABLE playground_prompt_versions ADD CONSTRAINT playground_prompt_versions_temperature_check CHECK (temperature >= 0 AND temperature <= 2)');
        DB::statement('ALTER TABLE playground_prompt_versions ADD CONSTRAINT playground_prompt_versions_max_tokens_check CHECK (max_tokens BETWEEN 16 AND 2048)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playground_prompt_versions');
    }
};
