<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE listing_documents ALTER COLUMN embedded_at TYPE timestamp(6) with time zone');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE listing_documents ALTER COLUMN embedded_at TYPE timestamp(0) with time zone');
    }
};
