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
        Schema::create('listing_tags', function (Blueprint $table) {
            $table->bigInteger('id')->generatedAs()->primary();
            $table->bigInteger('listing_id');
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
            $table->string('tag', 30);
            $table->timestampTz('created_at')->nullable();

            $table->unique(['listing_id', 'tag']);
            $table->index('tag');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_tags');
    }
};
