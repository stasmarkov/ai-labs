<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array WRITE_OUTCOMES = ['pending', 'confirmed', 'rejected'];

    /**
     * Run the migrations.
     *
     * The column grows from 8 to 10 characters because "confirmed" is 9; the error check keeps its old rule for the
     * old outcomes and leaves the write outcomes free to carry an error or not.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE ai_tool_steps DROP CONSTRAINT ai_tool_steps_outcome_check');
        DB::statement('ALTER TABLE ai_tool_steps DROP CONSTRAINT ai_tool_steps_error_check');
        DB::statement('ALTER TABLE ai_tool_steps ALTER COLUMN outcome TYPE varchar(10)');
        DB::statement("ALTER TABLE ai_tool_steps ADD CONSTRAINT ai_tool_steps_outcome_check CHECK (outcome IN ('ok', 'error', 'failed', 'pending', 'confirmed', 'rejected'))");
        DB::statement("ALTER TABLE ai_tool_steps ADD CONSTRAINT ai_tool_steps_error_check CHECK (outcome IN ('pending', 'confirmed', 'rejected') OR (outcome = 'ok') = (error IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $rows = DB::table('ai_tool_steps')->whereIn('outcome', self::WRITE_OUTCOMES)->count();

        if ($rows > 0) {
            throw new RuntimeException("Cannot roll back: {$rows} ai_tool_steps rows use the pending, confirmed or rejected outcome. Delete them first.");
        }

        DB::statement('ALTER TABLE ai_tool_steps DROP CONSTRAINT ai_tool_steps_error_check');
        DB::statement('ALTER TABLE ai_tool_steps DROP CONSTRAINT ai_tool_steps_outcome_check');
        DB::statement('ALTER TABLE ai_tool_steps ALTER COLUMN outcome TYPE varchar(8)');
        DB::statement("ALTER TABLE ai_tool_steps ADD CONSTRAINT ai_tool_steps_outcome_check CHECK (outcome IN ('ok', 'error', 'failed'))");
        DB::statement("ALTER TABLE ai_tool_steps ADD CONSTRAINT ai_tool_steps_error_check CHECK ((outcome = 'ok') = (error IS NULL))");
    }
};
