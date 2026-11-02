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
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs()->primary();
            $table->string('invocation_id', 64)->unique();
            $table->string('agent', 120);
            $table->string('provider', 60);
            $table->string('model', 120);
            $table->string('prompt_version', 80)->nullable();
            $table->integer('tokens_in')->nullable();
            $table->integer('tokens_out')->nullable();
            $table->integer('ttft_ms')->nullable();
            $table->integer('latency_ms');
            $table->string('status', 16);
            $table->string('error_class', 255)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('completed', 'failed'))");
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_tokens_in_check CHECK (tokens_in IS NULL OR tokens_in >= 0)');
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_tokens_out_check CHECK (tokens_out IS NULL OR tokens_out >= 0)');
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_ttft_ms_check CHECK (ttft_ms IS NULL OR ttft_ms >= 0)');
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_latency_ms_check CHECK (latency_ms >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
