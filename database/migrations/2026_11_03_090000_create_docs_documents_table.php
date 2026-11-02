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
        Schema::create('docs_documents', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16);
            $table->string('path', 255);
            $table->string('title', 255);
            $table->char('content_hash', 64);
            $table->timestampTz('indexed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['source', 'path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('docs_documents');
    }
};
