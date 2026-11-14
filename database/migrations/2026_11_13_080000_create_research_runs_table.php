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
        Schema::create('research_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('topic', 300);
            $table->string('status', 16)->index();
            $table->jsonb('sub_questions')->nullable();
            $table->text('report')->nullable();
            $table->jsonb('sources')->nullable();
            $table->unsignedSmallInteger('model_calls')->default(0);
            $table->unsignedSmallInteger('steps')->default(0);
            $table->unsignedSmallInteger('failed_notes')->default(0);
            $table->unsignedSmallInteger('dropped_chunk_ids')->default(0);
            $table->unsignedSmallInteger('unknown_citations')->default(0);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->string('provider', 60);
            $table->string('model', 120);
            $table->jsonb('prompt_versions');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestampsTz();
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE research_runs ADD CONSTRAINT research_runs_status_check CHECK (status IN ('running', 'done', 'failed', 'refused'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('research_runs');
    }
};
