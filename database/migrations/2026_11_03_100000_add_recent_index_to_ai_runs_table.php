<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The lab overview reads the 20 newest runs (ORDER BY created_at DESC, id DESC LIMIT 20);
 * without this index PostgreSQL scans and sorts the whole table on every load.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX ai_runs_recent_idx ON ai_runs (created_at DESC, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_runs_recent_idx');
    }
};
