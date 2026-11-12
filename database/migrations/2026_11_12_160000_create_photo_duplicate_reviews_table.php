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
        Schema::create('photo_duplicate_reviews', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('group_id')->unique();
            $table->foreign('group_id')->references('id')->on('photo_duplicate_groups')->cascadeOnDelete();
            $table->bigInteger('kept_photo_id');
            $table->foreign('kept_photo_id')->references('id')->on('listing_photos')->cascadeOnDelete();
            $table->string('source', 16);
            $table->timestampTz('reviewed_at')->useCurrent();
        });

        DB::statement("ALTER TABLE photo_duplicate_reviews ADD CONSTRAINT photo_duplicate_reviews_source_check CHECK (source IN ('model', 'override'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_duplicate_reviews');
    }
};
