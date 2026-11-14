<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\PlaygroundRunRepositoryInterface;
use App\DTOs\NewPlaygroundRun;
use App\DTOs\PlaygroundResultRecord;
use App\DTOs\PlaygroundRunRecord;
use App\DTOs\PlaygroundRunSummary;
use App\DTOs\PlaygroundStatistics;
use App\Enums\PlaygroundResultStatus;
use App\Models\PlaygroundPrompt;
use App\Models\PlaygroundPromptVersion;
use App\Models\PlaygroundResult;
use App\Models\PlaygroundRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EloquentPlaygroundRunRepository implements PlaygroundRunRepositoryInterface
{
    public function create(NewPlaygroundRun $run): PlaygroundRunRecord
    {
        return DB::transaction(function () use ($run): PlaygroundRunRecord {
            $stored = PlaygroundRun::query()->create([
                'playground_prompt_version_id' => $run->promptVersionId,
                'system_prompt' => $run->systemPrompt,
                'input' => $run->input,
                'input_flags' => $run->inputFlags,
                'temperature' => $run->temperature,
                'max_tokens' => $run->maxTokens,
                'version_label' => mb_substr($run->versionLabel, 0, 160),
            ]);

            foreach ($run->results as $result) {
                $stored->results()->create([
                    'position' => $result->position,
                    'provider' => $result->provider,
                    'model' => $result->model,
                    'status' => $result->status->value,
                    'output' => $result->output,
                    'error' => $result->error === null ? null : mb_substr($result->error, 0, 300),
                    'tokens_in' => $result->tokensIn,
                    'tokens_out' => $result->tokensOut,
                    'ttft_ms' => $result->ttftMs,
                    'duration_ms' => max(0, $result->durationMs),
                    'ai_run_invocation_id' => $result->aiRunInvocationId,
                ]);
            }

            return $this->record($stored->refresh()->load('results'));
        });
    }

    public function find(string $id): ?PlaygroundRunRecord
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $run = PlaygroundRun::query()->with('results')->find($id);

        return $run === null ? null : $this->record($run);
    }

    public function recent(int $limit): array
    {
        return PlaygroundRun::query()
            ->select(['id', 'version_label', 'created_at'])
            ->selectRaw('substring(input from 1 for 80) as input_preview')
            ->with('results:id,playground_run_id,position,provider,model')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (PlaygroundRun $run): PlaygroundRunSummary => new PlaygroundRunSummary(
                id: $run->id,
                versionLabel: $run->version_label,
                input: (string) $run->getAttribute('input_preview'),
                models: $run->results->map(fn (PlaygroundResult $result): string => "{$result->provider}:{$result->model}")->values()->all(),
                createdAt: $run->created_at,
            ))
            ->all();
    }

    public function statistics(): PlaygroundStatistics
    {
        $localProviders = collect(array_keys((array) config('ai.providers', [])))
            ->filter(fn (string $provider): bool => config("ai.providers.{$provider}.driver") === 'ollama')
            ->values()
            ->all();

        $twoModelLocal = PlaygroundResult::query()
            ->selectRaw('playground_run_id, sum(duration_ms) as total_ms')
            ->groupBy('playground_run_id')
            ->havingRaw('count(*) = 2')
            ->havingRaw('sum(case when status = ? then 1 else 0 end) = 2', [PlaygroundResultStatus::Ok->value])
            ->when(
                $localProviders === [],
                fn ($query) => $query->havingRaw('1 = 0'),
                fn ($query) => $query->havingRaw('sum(case when provider in ('.implode(',', array_fill(0, count($localProviders), '?')).') then 1 else 0 end) = 2', $localProviders),
            )
            ->toBase()
            ->get();

        $runs = PlaygroundRun::query()->count();

        return new PlaygroundStatistics(
            runs: $runs,
            averageModelsPerRun: $runs === 0 ? 0.0 : round(PlaygroundResult::query()->count() / $runs, 1),
            prompts: PlaygroundPrompt::query()->count(),
            versions: PlaygroundPromptVersion::query()->count(),
            twoModelLocalRuns: $twoModelLocal->count(),
            averageTwoModelLocalDurationMs: $twoModelLocal->isEmpty() ? 0 : (int) round((float) $twoModelLocal->avg('total_ms')),
        );
    }

    private function record(PlaygroundRun $run): PlaygroundRunRecord
    {
        return new PlaygroundRunRecord(
            id: $run->id,
            promptVersionId: $run->playground_prompt_version_id,
            versionLabel: $run->version_label,
            systemPrompt: $run->system_prompt,
            input: $run->input,
            inputFlags: $run->input_flags,
            temperature: $run->temperature,
            maxTokens: $run->max_tokens,
            createdAt: $run->created_at,
            results: $run->results->map(fn (PlaygroundResult $result): PlaygroundResultRecord => new PlaygroundResultRecord(
                position: $result->position,
                provider: $result->provider,
                model: $result->model,
                status: $result->status,
                output: $result->output,
                error: $result->error,
                tokensIn: $result->tokens_in,
                tokensOut: $result->tokens_out,
                ttftMs: $result->ttft_ms,
                durationMs: $result->duration_ms,
                aiRunInvocationId: $result->ai_run_invocation_id,
            ))->values()->all(),
        );
    }
}
