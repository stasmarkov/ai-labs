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
            $table->string('cache_kind', 16)->nullable();
            $table->double('cache_similarity')->nullable();
            $table->unsignedBigInteger('cache_entry_id')->nullable();
            $table->integer('saved_latency_ms')->nullable();
            $table->string('origin', 8)->nullable();
        });

        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_status_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('completed', 'failed', 'refused', 'no_context', 'cached'))");
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_cache_kind_check CHECK (cache_kind IS NULL OR cache_kind IN ('exact', 'semantic'))");
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_origin_check CHECK (origin IS NULL OR origin IN ('api', 'eval'))");
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_saved_latency_ms_check CHECK (saved_latency_ms IS NULL OR saved_latency_ms >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DELETE FROM ai_runs WHERE status = 'cached'");
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_status_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('completed', 'failed', 'refused', 'no_context'))");

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn(['cache_kind', 'cache_similarity', 'cache_entry_id', 'saved_latency_ms', 'origin']);
        });
    }
};
