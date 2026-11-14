<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\PlaygroundRunRepositoryInterface;
use App\DTOs\PlaygroundStatistics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('playground:stats {--output-dir= : Directory for stats.json, replacing bench/results/playground/<UTC date>}')]
#[Description('Write the run, prompt and version counts of the stored playground data for the playground guide')]
final class PlaygroundStatsCommand extends Command
{
    private const FILE = 'stats.json';

    public function handle(PlaygroundRunRepositoryInterface $runs): int
    {
        $statistics = $runs->statistics();

        if ($statistics->runs === 0) {
            $this->error('No playground run is stored yet: run a comparison on the playground page first.');

            return self::FAILURE;
        }

        $directory = $this->directory();
        File::ensureDirectoryExists($directory);
        File::put("{$directory}/".self::FILE, json_encode($this->payload($statistics), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->info("Wrote {$directory}/".self::FILE." for {$statistics->runs} runs.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PlaygroundStatistics $statistics): array
    {
        return [
            'metadata' => [
                'generated_at' => now()->utc()->toIso8601String(),
                'two_model_local' => 'runs of exactly two targets, both ok, both on a local provider; the sum of the two call times',
            ],
            'summary' => [
                'runs' => $statistics->runs,
                'average_models_per_run' => $statistics->averageModelsPerRun,
                'prompts' => $statistics->prompts,
                'versions' => $statistics->versions,
                'two_model_local_runs' => $statistics->twoModelLocalRuns,
                'average_two_model_local_duration_s' => round($statistics->averageTwoModelLocalDurationMs / 1000, 1),
            ],
        ];
    }

    private function directory(): string
    {
        $option = (string) $this->option('output-dir');

        if ($option !== '') {
            return str_starts_with($option, '/') ? $option : base_path($option);
        }

        return base_path('bench/results/playground/'.now()->utc()->format('Y-m-d'));
    }
}
