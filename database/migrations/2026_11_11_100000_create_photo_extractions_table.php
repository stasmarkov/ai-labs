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
        Schema::create('photo_extractions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('photo_id')->index();
            $table->foreign('photo_id')->references('id')->on('listing_photos')->cascadeOnDelete();
            $table->string('provider', 60);
            $table->string('model', 120);
            $table->string('prompt_version', 80);
            $table->string('status', 16)->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->jsonb('data')->nullable();
            $table->jsonb('errors')->nullable();
            $table->jsonb('invocation_ids')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->timestampsTz();

            $table->unique(['photo_id', 'provider', 'model', 'prompt_version']);
        });

        DB::statement("ALTER TABLE photo_extractions ADD CONSTRAINT photo_extractions_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed'))");
        DB::statement('ALTER TABLE photo_extractions ADD CONSTRAINT photo_extractions_attempts_check CHECK (attempts >= 0)');
        DB::statement('ALTER TABLE photo_extractions ADD CONSTRAINT photo_extractions_latency_ms_check CHECK (latency_ms IS NULL OR latency_ms >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_extractions');
    }
};
