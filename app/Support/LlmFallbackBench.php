<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\AiRunRepositoryInterface;
use App\Exceptions\LlmBenchException;
use App\Services\LlmEvalFigures;
use App\Services\NlEvalScorer;
use Closure;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Laravel\Ai\AiManager;

/**
 * Measures what a smaller fallback model on the same Ollama buys: the failover matrix with the fallback on, describe
 * streams in parallel with and without a first-token budget, and the fallback model's eval figures next to the main
 * model's committed ones. What touches the outside (parallel processes, the eval commands, the host load) is a closure,
 * so a test can replace it.
 */
final readonly class LlmFallbackBench
{
    public const REPEATS = 3;

    public const FEATURES = ['nl-search', 'moderation'];

    private Closure $parallel;

    private Closure $eval;

    private Closure $load;

    /**
     * @param  (Closure(list<Closure(): array<string, mixed>>): list<array<string, mixed>>)|null  $parallel  Runs the tasks at the same time, results in order.
     * @param  (Closure(string, string): array<string, mixed>)|null  $eval  Feature and model to the decoded run file of that eval.
     * @param  (Closure(): array{0: float, 1: float, 2: float})|null  $load  The 1, 5 and 15 minute load average of the host.
     */
    public function __construct(
        private LlmSpeedBench $speed,
        private LlmFailoverBench $failover,
        private AiRunRepositoryInterface $runs,
        private LlmEvalFigures $figures,
        ?Closure $parallel = null,
        ?Closure $eval = null,
        ?Closure $load = null,
    ) {
        $this->parallel = $parallel ?? static fn (array $tasks): array => array_values(app(ConcurrencyManager::class)->driver('process')->run($tasks));
        $this->eval = $eval ?? $this->runEval(...);
        $this->load = $load ?? static function (): array {
            $load = sys_getloadavg();

            return $load === false ? [0.0, 0.0, 0.0] : [$load[0], $load[1], $load[2]];
        };
    }

    /**
     * Fails before anything is measured when the fallback model is not pulled.
     */
    public function assertInstalled(string $fallbackModel): void
    {
        if (! in_array($fallbackModel, $this->speed->installed(), true)) {
            throw LlmBenchException::fallbackModelMissing($fallbackModel);
        }
    }

    /**
     * @return array<string, float>
     */
    public function hostLoad(): array
    {
        [$one, $five, $fifteen] = ($this->load)();

        return ['1m' => round($one, 2), '5m' => round($five, 2), '15m' => round($fifteen, 2)];
    }

    /**
     * The SL-28 failover row of the main model is put next to each row of the matrix with the fallback on.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function faults(string $model, string $fallbackModel, string $resultsRoot): array
    {
        $matrix = $this->failover->measure($model, $fallbackModel);
        [$baseline, $source] = $this->baseline($model, $resultsRoot);

        foreach ($matrix as $fault => $entries) {
            foreach (array_keys($entries) as $entry) {
                $old = $baseline[$fault][$entry] ?? null;
                $matrix[$fault][$entry]['sl28'] = is_array($old) ? ['status' => $old['status'] ?? null, 'time_to_fail_ms' => $old['time_to_fail_ms'] ?? null, 'source' => $source] : null;
            }
        }

        return $matrix;
    }

    /**
     * @param  list<int>  $levels
     * @return array<int, array{without_budget: array<string, mixed>, with_budget: array<string, mixed>}>
     */
    public function busy(string $model, string $fallbackModel, int $budgetMs, array $levels): array
    {
        $result = [];

        foreach ($levels as $level) {
            $result[$level] = [
                'without_budget' => $this->level($model, $fallbackModel, null, $level),
                'with_budget' => $this->level($model, $fallbackModel, $budgetMs, $level),
            ];
        }

        return $result;
    }

    /**
     * The NFR-2 figure: describe at 1 parallel with the fallback on and no budget, nothing broken. It is the 1-stream
     * row of the busy runs when those include 1, else it is measured on its own.
     *
     * @param  array<int, array{without_budget: array<string, mixed>, with_budget: array<string, mixed>}>  $busy
     * @return array<string, mixed>
     */
    public function noFault(string $model, string $fallbackModel, array $busy): array
    {
        $row = $busy[1]['without_budget'] ?? $this->level($model, $fallbackModel, null, 1);

        return ['parallel' => 1, 'budget_ms' => null, 'ttft_ms' => $row['ttft_ms'], 'fallback_share' => $row['fallback_share'], 'failed' => $row['failed']];
    }

    /**
     * @return array<string, array{main: array<string, mixed>, fallback: array<string, mixed>}>
     */
    public function evals(string $model, string $fallbackModel): array
    {
        $main = $this->figures->forModel($model);
        $result = [];

        foreach (self::FEATURES as $feature) {
            $data = ($this->eval)($feature, $fallbackModel);
            $figure = $this->figures->fromRunData($feature, $data, $fallbackModel);

            $result[$feature] = [
                'main' => $main[$feature],
                'fallback' => $figure ?? ['json_valid' => null, 'accuracy' => null, 'accuracy_metric' => null, 'reason' => "the {$feature} eval has no report for {$fallbackModel}"],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function fallbackSpeed(string $fallbackModel, int $runs): array
    {
        return $this->speed->measure($fallbackModel, $runs);
    }

    /**
     * One describe stream through the HTTP kernel with the main model, the fallback model and the budget in place; the
     * time to the first text delta is read as the response is written. Config and the request are put back afterwards.
     *
     * @return array{ttft_ms: int|null, invocation_id: string|null}
     */
    public function describeOnce(string $model, string $fallbackModel, ?int $budgetMs): array
    {
        $config = (array) config('ai');
        $current = app('request');

        try {
            config([
                'ai.settings_ui' => false,
                'ai.describe_per_minute' => 1_000_000,
                'ai.features.describe.provider' => 'ollama',
                'ai.features.describe.model' => $model,
                'ai.features.describe.fallback_model' => $fallbackModel,
                'ai.features.describe.fallback_after_ms' => $budgetMs,
            ]);
            app(AiManager::class)->forgetInstance('ollama');

            $request = Request::create('/listings/'.LlmSpeedBench::LISTING_ID.'/describe', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/event-stream']);
            $kernel = app(Kernel::class);
            $started = hrtime(true);
            $response = $kernel->handle($request);
            $captured = '';
            $first = null;

            ob_start(static function (string $buffer) use (&$captured, &$first, $started): string {
                $captured .= $buffer;

                if ($first === null && str_contains($captured, '"type":"text_delta"')) {
                    $first = (int) round((hrtime(true) - $started) / 1_000_000);
                }

                return '';
            }, 1);

            try {
                $response->sendContent();
            } finally {
                ob_end_clean();
            }

            $kernel->terminate($request, $response);

            return ['ttft_ms' => $first, 'invocation_id' => $this->invocationId($captured)];
        } finally {
            config(['ai' => $config]);
            app(AiManager::class)->forgetInstance('ollama');
            app()->instance('request', $current);
            Facade::clearResolvedInstance('request');
        }
    }

    /**
     * A warm-up batch that is thrown away, then the repeats; the figures of the level are the median of the repeats.
     *
     * @return array<string, mixed>
     */
    private function level(string $model, string $fallbackModel, ?int $budgetMs, int $level): array
    {
        $this->batch($model, $fallbackModel, $budgetMs, $level);
        $repeats = [];

        for ($repeat = 0; $repeat < self::REPEATS; $repeat++) {
            $repeats[] = $this->batch($model, $fallbackModel, $budgetMs, $level);
        }

        return [
            'budget_ms' => $budgetMs,
            'requests' => $level,
            'ttft_ms' => [
                'p50' => $this->median(array_column(array_column($repeats, 'ttft_ms'), 'p50')),
                'p95' => $this->median(array_column(array_column($repeats, 'ttft_ms'), 'p95')),
            ],
            'fallback_share' => round((float) $this->median(array_column($repeats, 'fallback_share'), 3), 3),
            'fallback_reasons' => $this->reasonTotals($repeats),
            'failed' => (int) $this->median(array_column($repeats, 'failed')),
            'repeats' => $repeats,
        ];
    }

    /**
     * One batch of parallel describes; the run rows are read back in one query. The reasons count every request the
     * fallback answered, by its fallback_reason, so a busy row shows whether the budget (busy) or an error moved it.
     *
     * @return array{ttft_ms: array{p50: int|null, p95: int|null}, fallback_share: float, fallback_reasons: array<string, int>, failed: int}
     */
    private function batch(string $model, string $fallbackModel, ?int $budgetMs, int $level): array
    {
        $tasks = array_fill(0, $level, static fn (): array => app(self::class)->describeOnce($model, $fallbackModel, $budgetMs));
        $results = ($this->parallel)($tasks);
        $runs = $this->runs->summariesByInvocationIds(array_values(array_filter(array_column($results, 'invocation_id'), is_string(...))));

        $times = [];
        $failed = 0;
        $fallbacks = 0;
        $reasons = [];

        foreach ($results as $result) {
            $id = $result['invocation_id'] ?? null;
            $run = is_string($id) ? ($runs[$id] ?? null) : null;

            if (! is_int($result['ttft_ms'] ?? null) || $run?->status === 'failed') {
                $failed++;

                continue;
            }

            $times[] = $result['ttft_ms'];

            if ($run?->fallbackFromModel !== null) {
                $fallbacks++;
                $reason = $run->fallbackReason ?? 'unknown';
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }

        ksort($reasons);

        return [
            'ttft_ms' => ['p50' => NlEvalScorer::percentile($times, 50), 'p95' => NlEvalScorer::percentile($times, 95)],
            'fallback_share' => $level === 0 ? 0.0 : round($fallbacks / $level, 3),
            'fallback_reasons' => $reasons,
            'failed' => $failed,
        ];
    }

    /**
     * The fallback reasons of the repeats added up (the warm-up is left out), so the counts read against
     * requests × repeats.
     *
     * @param  list<array{fallback_reasons: array<string, int>}>  $repeats
     * @return array<string, int>
     */
    private function reasonTotals(array $repeats): array
    {
        $totals = [];

        foreach ($repeats as $repeat) {
            foreach ($repeat['fallback_reasons'] as $reason => $count) {
                $totals[$reason] = ($totals[$reason] ?? 0) + $count;
            }
        }

        ksort($totals);

        return $totals;
    }

    /**
     * @param  array<int, int|float|null>  $values
     */
    private function median(array $values, int $precision = 0): int|float|null
    {
        $values = array_values(array_filter($values, is_numeric(...)));

        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

        return $precision === 0 ? (int) round($median) : round($median, $precision);
    }

    private function invocationId(string $body): ?string
    {
        foreach (explode("\n", $body) as $line) {
            $event = str_starts_with($line, 'data: ') ? json_decode(substr($line, 6), true) : null;

            if (is_array($event) && is_string($event['invocation_id'] ?? null)) {
                return $event['invocation_id'];
            }
        }

        return null;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string|null} the failover section of the latest SL-28 run of the model, and its path
     */
    private function baseline(string $model, string $resultsRoot): array
    {
        $root = rtrim($resultsRoot, '/').'/llm';
        $folders = collect(File::isDirectory($root) ? File::directories($root) : [])->map(basename(...))->sortDesc()->values();

        foreach ($folders as $folder) {
            $path = "{$root}/{$folder}/run.json";
            $data = File::isFile($path) ? json_decode((string) File::get($path), true) : null;
            $failover = is_array($data) ? ($data['models'][$model]['failover'] ?? null) : null;

            if (is_array($failover) && ! isset($failover['error'])) {
                return [$failover, "bench/results/llm/{$folder}/run.json"];
            }
        }

        return [[], null];
    }

    /**
     * Runs the eval command of the feature on the fallback model, pinned, and reads its run file back.
     *
     * @return array<string, mixed>
     */
    private function runEval(string $feature, string $model): array
    {
        $root = rtrim((string) config('ai.docs.eval_results_root'), '/');
        $directory = "{$root}/llm-fallback/".now('UTC')->format('Y-m-d')."/evals/{$feature}";
        $config = (array) config('ai');

        try {
            config([
                "ai.features.{$feature}.provider" => 'ollama',
                'ai.nl_search.models' => [$model],
            ]);
            $code = Artisan::call($feature === 'nl-search' ? 'nl:eval' : 'moderation:eval', [
                '--models' => $model,
                '--output-dir' => $directory,
                '--force' => true,
                ...($feature === 'nl-search' ? ['--label' => 'run'] : ['--provider' => 'ollama']),
            ]);
        } finally {
            config(['ai' => $config]);
        }

        $path = "{$directory}/run.json";
        $data = $code === 0 && File::isFile($path) ? json_decode((string) File::get($path), true) : null;

        if (! is_array($data)) {
            throw LlmBenchException::evalFailed($feature, $model);
        }

        return $data;
    }
}
