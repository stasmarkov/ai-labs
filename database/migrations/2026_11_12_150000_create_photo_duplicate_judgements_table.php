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
        Schema::create('photo_duplicate_judgements', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('group_id');
            $table->foreign('group_id')->references('id')->on('photo_duplicate_groups')->cascadeOnDelete();
            $table->string('provider', 60);
            $table->string('model', 120);
            $table->string('prompt_version', 80);
            $table->string('status', 16)->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->bigInteger('best_photo_id')->nullable();
            $table->foreign('best_photo_id')->references('id')->on('listing_photos')->nullOnDelete();
            $table->jsonb('reasons')->nullable();
            $table->jsonb('issues')->nullable();
            $table->jsonb('errors')->nullable();
            $table->jsonb('invocation_ids')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->timestampsTz();

            $table->unique(['group_id', 'provider', 'model', 'prompt_version']);
        });

        DB::statement("ALTER TABLE photo_duplicate_judgements ADD CONSTRAINT photo_duplicate_judgements_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed'))");
        DB::statement('ALTER TABLE photo_duplicate_judgements ADD CONSTRAINT photo_duplicate_judgements_attempts_check CHECK (attempts >= 0)');
        DB::statement('ALTER TABLE photo_duplicate_judgements ADD CONSTRAINT photo_duplicate_judgements_latency_ms_check CHECK (latency_ms IS NULL OR latency_ms >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_duplicate_judgements');
    }
};
