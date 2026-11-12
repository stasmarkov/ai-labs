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
            $table->bigInteger('duplicate_of')->nullable()->index();
            $table->foreign('duplicate_of')->references('id')->on('listing_photos')->cascadeOnDelete();
            $table->string('variant', 16)->nullable();
            $table->string('review_status', 24)->nullable();
        });

        DB::statement("ALTER TABLE listing_photos ADD CONSTRAINT listing_photos_variant_check CHECK (variant IS NULL OR variant IN ('downscaled', 'recompressed', 'cropped', 'darkened', 'mirrored'))");
        DB::statement("ALTER TABLE listing_photos ADD CONSTRAINT listing_photos_review_status_check CHECK (review_status IS NULL OR review_status IN ('kept', 'marked_for_removal'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE listing_photos DROP CONSTRAINT IF EXISTS listing_photos_variant_check');
        DB::statement('ALTER TABLE listing_photos DROP CONSTRAINT IF EXISTS listing_photos_review_status_check');

        Schema::table('listing_photos', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of']);
            $table->dropColumn(['duplicate_of', 'variant', 'review_status']);
        });
    }
};
