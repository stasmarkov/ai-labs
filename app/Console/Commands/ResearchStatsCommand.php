<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ResearchRunRepositoryInterface;
use App\DTOs\ResearchRunStatistics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('research:stats {--output-dir= : Directory for stats.json, replacing bench/results/research/<UTC date>}')]
#[Description('Write the run counts and averages of the stored research runs for the research guide')]
final class ResearchStatsCommand extends Command
{
    private const FILE = 'stats.json';

    public function handle(ResearchRunRepositoryInterface $runs): int
    {
        $statistics = $runs->statistics();

        if ($statistics->runs === 0) {
            $this->error('No research run is stored yet: run agent:research first.');

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
    private function payload(ResearchRunStatistics $statistics): array
    {
        $executed = $statistics->done + $statistics->failed;

        return [
            'metadata' => [
                'generated_at' => now()->utc()->toIso8601String(),
                'models' => $statistics->models,
                'averages_over' => 'runs that ended done or failed',
            ],
            'summary' => [
                'runs' => $statistics->runs,
                'done' => $statistics->done,
                'failed' => $statistics->failed,
                'refused' => $statistics->refused,
                'running' => $statistics->running,
                'done_percent' => $executed === 0 ? 0 : (int) round(100 * $statistics->done / $executed),
                'average_model_calls' => $statistics->averageModelCalls,
                'max_model_calls' => $statistics->maxModelCalls,
                'average_steps' => $statistics->averageSteps,
                'average_duration_s' => round($statistics->averageDurationMs / 1000, 1),
            ],
        ];
    }

    private function directory(): string
    {
        $option = (string) $this->option('output-dir');

        if ($option !== '') {
            return str_starts_with($option, '/') ? $option : base_path($option);
        }

        return base_path('bench/results/research/'.now()->utc()->format('Y-m-d'));
    }
}
