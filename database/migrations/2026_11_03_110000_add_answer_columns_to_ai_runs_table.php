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
            $table->jsonb('retrieved_chunk_ids')->nullable();
            $table->jsonb('cited_chunk_ids')->nullable();
            $table->string('refusal_reason', 120)->nullable();
        });

        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_status_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('completed', 'failed', 'refused', 'no_context'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DELETE FROM ai_runs WHERE status IN ('refused', 'no_context')");
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_status_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('completed', 'failed'))");

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn(['retrieved_chunk_ids', 'cited_chunk_ids', 'refusal_reason']);
        });
    }
};
