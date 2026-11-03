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
        Schema::create('docs_answer_cache', function (Blueprint $table) {
            $table->id();
            $table->text('question');
            $table->char('question_hash', 64);
            $table->vector('embedding', 1024);
            $table->text('answer');
            $table->jsonb('sources');
            $table->jsonb('cited_chunk_ids');
            $table->jsonb('retrieved_chunk_ids');
            $table->string('provider', 60);
            $table->string('prompt_version', 80);
            $table->string('answer_model', 120);
            $table->string('embedding_model', 120);
            $table->char('index_version', 64);
            $table->string('source_invocation_id', 64);
            $table->unsignedInteger('source_latency_ms');
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestampTz('last_hit_at')->nullable();
            $table->timestampsTz();

            $table->unique(['question_hash', 'prompt_version', 'answer_model', 'embedding_model', 'index_version'], 'docs_answer_cache_version_key');
            $table->index(['prompt_version', 'answer_model', 'embedding_model', 'index_version'], 'docs_answer_cache_versions_idx');
        });

        DB::statement('CREATE INDEX docs_answer_cache_embedding_hnsw_idx ON docs_answer_cache USING hnsw (embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('docs_answer_cache');
    }
};
