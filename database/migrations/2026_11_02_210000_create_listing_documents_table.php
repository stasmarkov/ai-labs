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
        Schema::create('listing_documents', function (Blueprint $table) {
            $table->bigInteger('listing_id')->primary();
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
            $table->string('body_type', 16);
            $table->text('description');
            $table->vector('embedding', 1024)->nullable();
            $table->string('embedding_model', 120)->nullable();
            $table->char('embedded_text_hash', 64)->nullable();
            $table->timestampTz('embedded_at')->nullable();
            $table->timestampsTz();
        });

        // Name kept in sync with App\Models\ListingDocument::EMBEDDING_INDEX; migrations must not depend on app classes.
        DB::statement('CREATE INDEX listing_documents_embedding_hnsw_idx ON listing_documents USING hnsw (embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_documents');
    }
};
