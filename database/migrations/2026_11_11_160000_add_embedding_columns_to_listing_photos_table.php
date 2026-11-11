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
        Schema::table('listing_photos', function (Blueprint $table) {
            $table->vector('embedding', 512)->nullable();
            $table->string('embedding_model', 120)->nullable();
            $table->timestampTz('embedded_at', 6)->nullable();
        });

        DB::statement('CREATE INDEX listing_photos_embedding_hnsw_idx ON listing_photos USING hnsw (embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS listing_photos_embedding_hnsw_idx');

        Schema::table('listing_photos', function (Blueprint $table) {
            $table->dropColumn(['embedding', 'embedding_model', 'embedded_at']);
        });
    }
};
