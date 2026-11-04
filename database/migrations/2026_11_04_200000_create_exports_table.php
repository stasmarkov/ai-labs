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
        Schema::create('exports', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('path', 255);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('rows');
            $table->timestampTz('generated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
