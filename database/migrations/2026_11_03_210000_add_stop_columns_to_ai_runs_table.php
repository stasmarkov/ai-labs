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
            $table->unsignedSmallInteger('history_turns')->nullable();
            $table->integer('stopped_after_ms')->nullable();
        });

        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_status_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('completed', 'failed', 'refused', 'no_context', 'cached', 'stopped'))");
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_stopped_after_ms_check CHECK (stopped_after_ms IS NULL OR stopped_after_ms >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DELETE FROM ai_runs WHERE status = 'stopped'");
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_status_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('completed', 'failed', 'refused', 'no_context', 'cached'))");

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn(['history_turns', 'stopped_after_ms']);
        });
    }
};
