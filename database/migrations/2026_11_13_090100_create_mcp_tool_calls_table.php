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
        Schema::create('mcp_tool_calls', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs()->primary();
            $table->string('tool', 120);
            $table->jsonb('arguments');
            $table->jsonb('result_summary')->nullable();
            $table->string('outcome', 8);
            $table->string('error', 500)->nullable();
            $table->integer('duration_ms');
            $table->uuid('pending_change_id')->nullable()->index();
            $table->foreign('pending_change_id')->references('id')->on('pending_changes')->nullOnDelete();
            $table->timestampTz('created_at')->nullable();
        });

        DB::statement("ALTER TABLE mcp_tool_calls ADD CONSTRAINT mcp_tool_calls_outcome_check CHECK (outcome IN ('ok', 'error', 'proposed', 'failed'))");
        DB::statement('ALTER TABLE mcp_tool_calls ADD CONSTRAINT mcp_tool_calls_duration_ms_check CHECK (duration_ms >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_calls');
    }
};
