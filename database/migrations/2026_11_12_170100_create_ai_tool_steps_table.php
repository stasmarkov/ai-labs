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
        Schema::create('ai_tool_steps', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs()->primary();
            $table->bigInteger('ai_run_id')->index();
            $table->foreign('ai_run_id')->references('id')->on('ai_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('tool', 120);
            $table->jsonb('arguments');
            $table->string('outcome', 8);
            $table->string('error', 120)->nullable();
            $table->jsonb('result_summary')->nullable();
            $table->integer('duration_ms');
            $table->timestampTz('created_at')->nullable();

            $table->unique(['ai_run_id', 'position']);
        });

        DB::statement("ALTER TABLE ai_tool_steps ADD CONSTRAINT ai_tool_steps_outcome_check CHECK (outcome IN ('ok', 'error', 'failed'))");
        DB::statement('ALTER TABLE ai_tool_steps ADD CONSTRAINT ai_tool_steps_position_check CHECK (position >= 1)');
        DB::statement('ALTER TABLE ai_tool_steps ADD CONSTRAINT ai_tool_steps_duration_ms_check CHECK (duration_ms >= 0)');
        DB::statement("ALTER TABLE ai_tool_steps ADD CONSTRAINT ai_tool_steps_error_check CHECK ((outcome = 'ok') = (error IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_tool_steps');
    }
};
