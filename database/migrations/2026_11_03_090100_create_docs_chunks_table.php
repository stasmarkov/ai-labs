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
        Schema::create('docs_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('docs_documents')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('heading', 500)->nullable();
            $table->text('body');
            $table->text('content');
            $table->unsignedInteger('token_estimate');
            $table->boolean('oversize')->default(false);
            $table->char('content_hash', 64)->index();
            $table->vector('embedding', 1024)->nullable();
            $table->string('embedding_model', 120)->nullable();
            $table->timestampTz('embedded_at', 6)->nullable();
            $table->timestampsTz();

            $table->unique(['document_id', 'position']);
        });

        // Names kept in sync with App\Models\DocsChunk; migrations must not depend on app classes.
        DB::statement("ALTER TABLE docs_chunks ADD COLUMN tsv tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(heading, '') || ' ' || body)) STORED");
        DB::statement('CREATE INDEX docs_chunks_embedding_hnsw_idx ON docs_chunks USING hnsw (embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX docs_chunks_tsv_gin_idx ON docs_chunks USING gin (tsv)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('docs_chunks');
    }
};
