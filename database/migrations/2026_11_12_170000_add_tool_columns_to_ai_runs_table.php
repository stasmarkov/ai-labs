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
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->unsignedSmallInteger('tool_steps')->nullable();
            $table->unsignedSmallInteger('tool_errors')->nullable();
        });

        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_tool_errors_check CHECK (tool_errors IS NULL OR (tool_steps IS NOT NULL AND tool_errors <= tool_steps))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn(['tool_steps', 'tool_errors']);
        });
    }
};
