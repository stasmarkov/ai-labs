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
        Schema::table('docs_documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('chunk_max_tokens')->nullable();
            $table->unsignedSmallInteger('chunk_merge_below_tokens')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('docs_documents', function (Blueprint $table) {
            $table->dropColumn(['chunk_max_tokens', 'chunk_merge_below_tokens']);
        });
    }
};
