<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('listing_photos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('listing_id')->index();
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('source', 40)->index();
            $table->string('source_class', 120);
            $table->string('source_file', 120);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->char('sha256', 64)->unique();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_photos');
    }
};
