<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\AiToolStepRepositoryInterface;
use App\DTOs\AiToolStepRecord;
use App\Models\AiRun;
use Illuminate\Support\Facades\DB;

final readonly class EloquentAiToolStepRepository implements AiToolStepRepositoryInterface
{
    public function append(string $invocationId, AiToolStepRecord $step): void
    {
        DB::transaction(function () use ($invocationId, $step): void {
            $run = AiRun::query()->where('invocation_id', $invocationId)->lockForUpdate()->first();

            if ($run === null) {
                return;
            }

            $run->steps()->create([
                'position' => $step->position,
                'tool' => $step->tool,
                'arguments' => $step->arguments,
                'outcome' => $step->outcome,
                'error' => $step->error,
                'result_summary' => $step->resultSummary,
                'duration_ms' => $step->durationMs,
            ]);

            $run->tool_steps = ($run->tool_steps ?? 0) + 1;
            $run->tool_errors = ($run->tool_errors ?? 0) + (in_array($step->outcome, ['error', 'failed'], true) ? 1 : 0);
            $run->save();
        });
    }
}
