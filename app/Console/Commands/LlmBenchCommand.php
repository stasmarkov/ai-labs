<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\LlmBenchException;
use App\Exceptions\RagEvalOptionException;
use App\Exceptions\RagEvalResultException;
use App\Services\LlmEvalFigures;
use App\Services\RagEvalResultWriter;
use App\Support\LlmBenchRedactor;
use App\Support\LlmContextBench;
use App\Support\LlmFailoverBench;
use App\Support\LlmSpeedBench;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Throwable;

#[Signature('llm:bench
    {--models=qwen3.5:9b,gemma4:12b : Comma-separated Ollama text models}
    {--runs=5 : Warm runs per model}
    {--only=speed,context,failover : Comma-separated sections to run (speed, context, failover)}
    {--force : Overwrite the run file of today}')]
#[Description('Measure local Ollama models: load time, time to first token, tokens per second and memory, next to the committed eval figures of each model')]
final class LlmBenchCommand extends Command
{
    private const EMPTY = '—';

    private const SECTIONS = ['speed', 'context', 'failover'];

    public function handle(LlmSpeedBench $speed, LlmContextBench $context, LlmFailoverBench $failover, LlmEvalFigures $evals, RagEvalResultWriter $writer): int
    {
        if (! app()->environment('local')) {
            $this->error('llm:bench unloads the models on the Ollama server and lifts the AI rate limits, so it only runs in the local environment (APP_ENV=local).');

            return 2;
        }

        $startedAt = Date::now('UTC');
        $models = array_values(array_unique(array_filter(array_map(trim(...), explode(',', (string) $this->option('models'))))));
        $sections = array_values(array_unique(array_filter(array_map(trim(...), explode(',', (string) $this->option('only'))))));
        $runs = filter_var($this->option('runs'), FILTER_VALIDATE_INT);

        if ($models === [] || $sections === [] || array_diff($sections, self::SECTIONS) !== [] || $runs === false || $runs < 1) {
            $this->error('Use --models with at least one model, --runs with a positive number and --only with: '.implode(', ', self::SECTIONS).'.');

            return self::FAILURE;
        }

        $sections = array_values(array_intersect(self::SECTIONS, $sections));

        try {
            $directory = $writer->contain(rtrim((string) config('ai.docs.eval_results_root'), '/').'/llm/'.$startedAt->format('Y-m-d'));
            $writer->assertWritableFile($directory, 'run.json', (bool) $this->option('force'));
        } catch (RagEvalOptionException|RagEvalResultException $e) {
            $this->error(LlmBenchRedactor::clean($e->getMessage()));

            return self::FAILURE;
        }

        $results = [];
        $failedSections = [];

        try {
            $version = $speed->version();

            foreach ($models as $model) {
                $results[$model] = [];

                foreach ($sections as $section) {
                    $this->line(match ($section) {
                        'speed' => "Measuring {$model}: 1 cold run and {$runs} warm runs",
                        'context' => "Checking the context window of {$model}",
                        default => "Breaking {$model} on purpose: ".count(LlmFailoverBench::FAULTS).' faults, '.count(LlmFailoverBench::ENTRIES).' entry points',
                    });

                    $results[$model][$section] = $this->section($model, $section, $failedSections, match ($section) {
                        'speed' => fn (): array => $speed->measure($model, $runs),
                        'context' => fn (): array => $context->measure($model, $startedAt->format('Y-m-d')),
                        default => fn (): array => $failover->measure($model),
                    });
                }

                $results[$model]['evals'] = $evals->forModel($model);
            }

            $writer->writeFile($directory, 'run.json', [
                'metadata' => [
                    'generated_at' => $startedAt->toIso8601String(),
                    'provider' => 'ollama',
                    'ollama_version' => $version,
                    'num_ctx' => config('ai.providers.ollama.request_options.num_ctx'),
                    'runs' => $runs,
                    'sections' => $sections,
                    'models' => $models,
                ],
                'models' => LlmBenchRedactor::cleanAll($results),
            ], (bool) $this->option('force'));
        } catch (LlmBenchException|RagEvalResultException $e) {
            $this->error(LlmBenchRedactor::clean($e->getMessage()));

            return self::FAILURE;
        }

        if (in_array('speed', $sections, true)) {
            $this->table(
                ['Model', 'Load ms', 'TTFT ms (median)', 'TTFT min-max', 'Prompt tok/s', 'Gen tok/s (median)', 'Gen min-max', 'Memory GB', 'VRAM GB', 'Failed runs'],
                array_map(fn (string $model): array => $this->row($model, $results[$model]['speed']), $models),
            );
        }

        if (in_array('context', $sections, true)) {
            $this->table(
                ['Model', 'num_ctx', 'Prompt tokens (est.)', 'Evaluated', 'Truncated', 'Recalled', 'Evaluated at 2x', 'Truncated at 2x', 'Recalled at 2x', 'Window (ps)', 'Window at 2x (ps)', 'Shift suspected'],
                array_map(fn (string $model): array => $this->contextRow($model, $results[$model]['context']), $models),
            );
        }

        if (in_array('failover', $sections, true)) {
            $this->table(
                ['Model', 'Fault', 'Entry point', 'HTTP', 'Client sees', 'Time to fail ms', 'Failed run'],
                array_merge(...array_map(fn (string $model): array => $this->failoverRows($model, $results[$model]['failover']), $models)),
            );
        }

        $this->info('Results written to '.$directory.'/run.json');

        if ($failedSections !== []) {
            $this->error('These sections failed and carry an error in the run file: '.implode(', ', $failedSections).'.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Runs one section of one model. An unexpected exception becomes the error of that section, so the sections that
     * did finish are still written.
     *
     * @param  list<string>  $failedSections
     * @param  Closure(): array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function section(string $model, string $section, array &$failedSections, Closure $run): array
    {
        try {
            return $run();
        } catch (Throwable $e) {
            $failedSections[] = "{$model} {$section}";
            $message = LlmBenchRedactor::clean($e->getMessage());
            $this->warn("{$model} {$section} failed: {$message}");

            return ['error' => $message, 'error_type' => class_basename($e)];
        }
    }

    /**
     * @param  array<string, mixed>  $speed
     * @return list<mixed>
     */
    private function row(string $model, array $speed): array
    {
        if (isset($speed['error'])) {
            return [$model, 'failed: '.$speed['error'], ...array_fill(0, 8, self::EMPTY)];
        }

        return [
            $model,
            $speed['load_ms'] ?? self::EMPTY,
            $speed['ttft_ms']['median'] ?? self::EMPTY,
            isset($speed['ttft_ms']) ? "{$speed['ttft_ms']['min']}-{$speed['ttft_ms']['max']}" : self::EMPTY,
            $speed['prompt_eval_tokens_per_s']['median'] ?? self::EMPTY,
            $speed['generation_tokens_per_s']['median'] ?? self::EMPTY,
            isset($speed['generation_tokens_per_s']) ? "{$speed['generation_tokens_per_s']['min']}-{$speed['generation_tokens_per_s']['max']}" : self::EMPTY,
            isset($speed['size']) ? round($speed['size'] / 1_000_000_000, 1) : self::EMPTY,
            isset($speed['size_vram']) ? round($speed['size_vram'] / 1_000_000_000, 1) : self::EMPTY,
            $speed['failed_runs'] ?? 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<mixed>
     */
    private function contextRow(string $model, array $context): array
    {
        $flag = static fn (mixed $value): string => $value === null ? self::EMPTY : ($value ? 'yes' : 'no');

        if (isset($context['error'])) {
            return [$model, 'failed: '.$context['error'], ...array_fill(0, 10, self::EMPTY)];
        }

        return [
            $model,
            $context['num_ctx'],
            $context['estimated_prompt_tokens'],
            $context['configured']['prompt_eval_count'] ?? self::EMPTY,
            $flag($context['configured']['truncated']),
            $flag($context['configured']['recalled']),
            $context['doubled']['prompt_eval_count'] ?? self::EMPTY,
            $flag($context['doubled']['truncated']),
            $flag($context['doubled']['recalled']),
            $context['effective_context_length'] ?? self::EMPTY,
            $context['doubled']['effective_context_length'] ?? self::EMPTY,
            $flag($context['configured']['context_shift_suspected'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $failover
     * @return list<list<mixed>>
     */
    private function failoverRows(string $model, array $failover): array
    {
        $rows = [];

        if (isset($failover['error'])) {
            return [[$model, 'failed: '.(string) $failover['error'], ...array_fill(0, 5, self::EMPTY)]];
        }

        foreach ($failover as $fault => $entries) {
            foreach ($entries as $entry => $row) {
                $client = $row['client']['kind'] === 'sse' ? 'event: '.$row['client']['event'] : ($row['client']['code'] ?? $row['client']['message'] ?? self::EMPTY);
                $rows[] = [$model, $fault, $entry, $row['status'], LlmBenchRedactor::clean((string) $client), $row['time_to_fail_ms'], $row['failed_run_written'] ? 'yes' : 'no'];
            }
        }

        return $rows;
    }
}
