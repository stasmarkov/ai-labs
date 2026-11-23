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
use App\Support\LlmFallbackBench;
use App\Support\LlmSpeedBench;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Throwable;

#[Signature('llm:bench
    {--models=qwen3.5:9b,gemma4:12b : Comma-separated Ollama text models}
    {--runs=5 : Warm runs per model}
    {--only=speed,context,failover : Comma-separated sections to run (speed, context, failover), or fallback alone}
    {--fallback-model= : Model of the fallback section (default: the describe fallback model)}
    {--budget-ms=2000 : First-token budget of the fallback section for the runs with a budget}
    {--parallel=1,4,16 : Parallel describe streams of the fallback section}
    {--force : Overwrite the run file of today}')]
#[Description('Measure local Ollama models: load time, time to first token, tokens per second and memory, next to the committed eval figures of each model')]
final class LlmBenchCommand extends Command
{
    private const EMPTY = '—';

    private const SECTIONS = ['speed', 'context', 'failover'];

    private const FALLBACK = 'fallback';

    public function handle(LlmSpeedBench $speed, LlmContextBench $context, LlmFailoverBench $failover, LlmFallbackBench $fallback, LlmEvalFigures $evals, RagEvalResultWriter $writer): int
    {
        if (! app()->environment('local')) {
            $this->error('llm:bench unloads the models on the Ollama server and lifts the AI rate limits, so it only runs in the local environment (APP_ENV=local).');

            return 2;
        }

        $startedAt = Date::now('UTC');
        $models = array_values(array_unique(array_filter(array_map(trim(...), explode(',', (string) $this->option('models'))))));
        $sections = array_values(array_unique(array_filter(array_map(trim(...), explode(',', (string) $this->option('only'))))));
        $runs = filter_var($this->option('runs'), FILTER_VALIDATE_INT);

        if (in_array(self::FALLBACK, $sections, true) && $sections !== [self::FALLBACK]) {
            $this->error('The fallback section runs alone: use --only=fallback.');

            return self::FAILURE;
        }

        if ($models === [] || $sections === [] || array_diff($sections, [...self::SECTIONS, self::FALLBACK]) !== [] || $runs === false || $runs < 1) {
            $this->error('Use --models with at least one model, --runs with a positive number and --only with: '.implode(', ', [...self::SECTIONS, self::FALLBACK]).'.');

            return self::FAILURE;
        }

        if ($sections === [self::FALLBACK]) {
            return $this->fallback($speed, $fallback, $writer, $models[0], $runs, $startedAt);
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
     * The fallback section: the first model is the main one. The result goes to llm-fallback/<date>/run.json with the
     * host load at the start and at the end, so a run on a busy host can be told from a quiet one.
     */
    private function fallback(LlmSpeedBench $speed, LlmFallbackBench $bench, RagEvalResultWriter $writer, string $model, int $runs, CarbonInterface $startedAt): int
    {
        $fallbackModel = trim((string) $this->option('fallback-model')) ?: (string) config('ai.features.describe.fallback_model');
        $budget = filter_var($this->option('budget-ms'), FILTER_VALIDATE_INT);
        $levels = array_values(array_unique(array_map(intval(...), array_filter(array_map(trim(...), explode(',', (string) $this->option('parallel'))), ctype_digit(...)))));

        if ($fallbackModel === '' || $fallbackModel === $model || $budget === false || $budget < 1 || $levels === [] || min($levels) < 1) {
            $this->error('Use a fallback model that differs from the main model, --budget-ms with a positive number and --parallel with positive numbers.');

            return self::FAILURE;
        }

        try {
            $root = rtrim((string) config('ai.docs.eval_results_root'), '/');
            $directory = $writer->contain("{$root}/llm-fallback/".$startedAt->format('Y-m-d'));
            $writer->assertWritableFile($directory, 'run.json', (bool) $this->option('force'));
            $bench->assertInstalled($fallbackModel);

            $loadAtStart = $bench->hostLoad();
            $version = $speed->version();

            $this->line("Breaking {$model} on purpose with {$fallbackModel} as the fallback: ".count(LlmFailoverBench::FAULTS).' faults, '.count(LlmFailoverBench::ENTRIES).' entry points');
            $faults = $bench->faults($model, $fallbackModel, $root);
            $this->line("Measuring the fallback model {$fallbackModel}");
            $fallbackSpeed = $bench->fallbackSpeed($fallbackModel, $runs);
            $this->line('Describe streams at '.implode(', ', $levels)." parallel, without and with a {$budget} ms budget: 1 warm-up and ".LlmFallbackBench::REPEATS.' repeats each');
            $busy = $bench->busy($model, $fallbackModel, $budget, $levels);
            $this->line("Running the evals of {$fallbackModel}");
            $evals = $bench->evals($model, $fallbackModel);

            $writer->writeFile($directory, 'run.json', LlmBenchRedactor::cleanAll([
                'metadata' => [
                    'generated_at' => $startedAt->toIso8601String(),
                    'provider' => 'ollama',
                    'ollama_version' => $version,
                    'num_ctx' => config('ai.providers.ollama.request_options.num_ctx'),
                    'main_model' => $model,
                    'fallback_model' => $fallbackModel,
                    'budget_ms' => $budget,
                    'parallel' => $levels,
                    'repeats' => LlmFallbackBench::REPEATS,
                    'runs' => $runs,
                    'host_load' => ['start' => $loadAtStart, 'end' => $bench->hostLoad()],
                ],
                'faults' => $faults,
                'fallback_speed' => $fallbackSpeed,
                'busy' => $busy,
                'evals' => $evals,
            ]), (bool) $this->option('force'));
        } catch (LlmBenchException|RagEvalOptionException|RagEvalResultException $e) {
            $this->error(LlmBenchRedactor::clean($e->getMessage()));

            return self::FAILURE;
        }

        $this->table(
            ['Fault', 'Entry point', 'HTTP', 'Answered by', 'Reason', 'Time ms', 'SL-28 time ms'],
            array_merge(...array_map(fn (string $fault): array => array_map(
                fn (string $entry): array => [$fault, $entry, $faults[$fault][$entry]['status'], $faults[$fault][$entry]['answered_by_model'] ?? self::EMPTY, $faults[$fault][$entry]['fallback_reason'] ?? self::EMPTY, $faults[$fault][$entry]['time_to_fail_ms'], $faults[$fault][$entry]['sl28']['time_to_fail_ms'] ?? self::EMPTY],
                array_keys($faults[$fault]),
            ), array_keys($faults))),
        );
        $this->table(
            ['Parallel', 'Budget ms', 'TTFT p50 ms', 'TTFT p95 ms', 'By fallback', 'Failed'],
            array_merge(...array_map(fn (int $level): array => array_map(
                fn (array $row): array => [$level, $row['budget_ms'] ?? 'none', $row['ttft_ms']['p50'] ?? self::EMPTY, $row['ttft_ms']['p95'] ?? self::EMPTY, round($row['fallback_share'] * 100).'%', $row['failed']],
                array_values($busy[$level]),
            ), $levels)),
        );

        $this->info('Results written to '.$directory.'/run.json');

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
