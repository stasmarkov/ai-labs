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
        Schema::create('playground_results', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs()->primary();
            $table->uuid('playground_run_id');
            $table->foreign('playground_run_id')->references('id')->on('playground_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('provider', 60);
            $table->string('model', 120);
            $table->string('status', 8);
            $table->text('output')->nullable();
            $table->string('error', 300)->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->integer('ttft_ms')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->string('ai_run_invocation_id', 64)->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->unique(['playground_run_id', 'position']);
        });

        DB::statement("ALTER TABLE playground_results ADD CONSTRAINT playground_results_status_check CHECK (status IN ('ok', 'failed', 'timeout'))");
        DB::statement('ALTER TABLE playground_results ADD CONSTRAINT playground_results_position_check CHECK (position BETWEEN 1 AND 3)');
        DB::statement('ALTER TABLE playground_results ADD CONSTRAINT playground_results_duration_ms_check CHECK (duration_ms >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playground_results');
    }
};
