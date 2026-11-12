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
        Schema::create('photo_duplicate_groups', function (Blueprint $table) {
            $table->id();
            $table->string('label', 160)->index();
            $table->double('cutoff');
            $table->boolean('was_split')->default(false);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('photo_duplicate_members', function (Blueprint $table) {
            $table->bigInteger('group_id');
            $table->foreign('group_id')->references('id')->on('photo_duplicate_groups')->cascadeOnDelete();
            $table->bigInteger('photo_id')->index();
            $table->foreign('photo_id')->references('id')->on('listing_photos')->cascadeOnDelete();
            $table->double('max_similarity');

            $table->primary(['group_id', 'photo_id']);
        });

        DB::statement('ALTER TABLE photo_duplicate_groups ADD CONSTRAINT photo_duplicate_groups_cutoff_check CHECK (cutoff >= 0 AND cutoff <= 1)');
        DB::statement('ALTER TABLE photo_duplicate_members ADD CONSTRAINT photo_duplicate_members_max_similarity_check CHECK (max_similarity >= -1 AND max_similarity <= 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_duplicate_members');
        Schema::dropIfExists('photo_duplicate_groups');
    }
};
