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
            $table->vector('text_embedding', 512)->nullable();
            $table->string('text_embedding_model', 120)->nullable();
            $table->string('text_source', 16)->nullable();
            $table->char('text_hash', 64)->nullable();
            $table->timestampTz('text_embedded_at', 6)->nullable();
        });

        DB::statement("ALTER TABLE listing_photos ADD CONSTRAINT listing_photos_text_source_check CHECK (text_source IS NULL OR text_source IN ('caption', 'title'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE listing_photos DROP CONSTRAINT IF EXISTS listing_photos_text_source_check');

        Schema::table('listing_photos', function (Blueprint $table) {
            $table->dropColumn(['text_embedding', 'text_embedding_model', 'text_source', 'text_hash', 'text_embedded_at']);
        });
    }
};
