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
        Schema::create('research_steps', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs()->primary();
            $table->uuid('research_run_id');
            $table->foreign('research_run_id')->references('id')->on('research_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('phase', 8);
            $table->unsignedSmallInteger('sub_question_index')->nullable();
            $table->jsonb('input');
            $table->jsonb('output')->nullable();
            $table->bigInteger('ai_run_id')->nullable();
            $table->foreign('ai_run_id')->references('id')->on('ai_runs')->nullOnDelete();
            $table->string('status', 8);
            $table->string('error', 500)->nullable();
            $table->integer('duration_ms')->default(0);
            $table->timestampTz('created_at')->nullable();
            $table->unique(['research_run_id', 'position']);
            $table->index('ai_run_id');
        });

        DB::statement("ALTER TABLE research_steps ADD CONSTRAINT research_steps_phase_check CHECK (phase IN ('plan', 'search', 'note', 'report'))");
        DB::statement("ALTER TABLE research_steps ADD CONSTRAINT research_steps_status_check CHECK (status IN ('ok', 'retried', 'failed', 'skipped'))");
        DB::statement('ALTER TABLE research_steps ADD CONSTRAINT research_steps_duration_ms_check CHECK (duration_ms >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('research_steps');
    }
};
