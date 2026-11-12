<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\ModerationAgent;
use App\DTOs\ModerationEvalOutcome;
use App\DTOs\ModerationInput;
use App\DTOs\ModerationSetItem;
use App\Exceptions\ProviderNotConfiguredException;
use App\Exceptions\ProviderNotEnabledException;
use App\Exceptions\RagEvalOptionException;
use App\Exceptions\RagEvalResultException;
use App\Exceptions\RagEvalSetException;
use App\Services\ModerationEvalScorer;
use App\Services\ModerationEvalSet;
use App\Services\ModerationService;
use App\Services\RagEvalResultWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use JsonException;

#[Signature('moderation:eval
    {--provider= : Provider for the run, replacing the moderation setting}
    {--models=qwen3.5:9b,gemma4:12b : Comma-separated text models of that provider; the set runs once per model}
    {--set= : Item set file (default: bench/moderation/listings.v1.jsonl)}
    {--output-dir= : Directory for run.json, replacing bench/results/moderation/<UTC date>}
    {--force : Overwrite an existing run.json}')]
#[Description('Measure the moderation classifier: JSON-valid rates, the threshold curve and best F1, accuracy, precision, recall, false positives on hard negatives, reason recall and latency')]
final class ModerationEvalCommand extends Command
{
    private const EMPTY = '—';

    private const FILE = 'run.json';

    public function handle(ModerationEvalSet $set, ModerationService $service, ModerationEvalScorer $scorer, RagEvalResultWriter $writer): int
    {
        $startedAt = Date::now('UTC');

        try {
            $directory = $writer->contain($this->directory($startedAt->format('Y-m-d')));
            $force = (bool) $this->option('force');
            $writer->assertWritableFile($directory, self::FILE, $force);
            $items = $set->load($this->option('set') ? $this->absolute((string) $this->option('set')) : null);
        } catch (RagEvalOptionException|RagEvalResultException|RagEvalSetException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $provider = $this->option('provider') ? (string) $this->option('provider') : null;
        $models = array_values(array_unique(array_filter(array_map(trim(...), explode(',', (string) $this->option('models'))))));

        if ($models === []) {
            $this->error('--models needs at least one model.');

            return self::FAILURE;
        }

        $agent = (new ModerationAgent)->usingProvider($provider);
        $reports = [];
        $results = [];

        try {
            foreach ($models as $model) {
                $this->line("Classifying {$model}: ".count($items).' items');
                $outcomes = [];

                foreach ($items as $item) {
                    $began = hrtime(true);
                    $result = $service->moderate($this->moderationInput($item), $provider, $model);
                    $outcomes[] = new ModerationEvalOutcome($item, $result, intdiv(hrtime(true) - $began, 1_000_000));
                }

                $reports[$model] = $scorer->score($model, $outcomes);
                $results[$model] = array_map(fn (ModerationEvalOutcome $outcome): array => [
                    'id' => $outcome->item->id,
                    'label' => $outcome->item->label,
                    'expected_reasons' => $outcome->item->reasons,
                    'status' => $outcome->result->status,
                    'attempts' => $outcome->result->attempts,
                    'risk' => $outcome->result->risk,
                    'reasons' => $outcome->result->reasons,
                    'latency_ms' => $outcome->latencyMs,
                ], $outcomes);
            }
        } catch (ProviderNotConfiguredException|ProviderNotEnabledException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Model', 'Valid 1st', 'Valid after retry', 'Best F1 at', 'Accuracy', 'Precision', 'Recall', 'F1', 'Hard-negative FP', 'Configured threshold', 'Recall there', 'Hard-negative FP there', 'Reason recall', 'p50 ms', 'p95 ms'],
            array_map(function (array $report): array {
                $best = $report['best_f1'];
                $hard = $report['false_positives_on_hard_negatives'];
                $configured = $report['false_positives_on_hard_negatives_at_configured_threshold'];

                return [
                    $report['model'],
                    $this->rate($report['json_valid_first_try']['rate']),
                    $this->rate($report['json_valid_after_retry']['rate']),
                    $best === null ? 'no threshold calibrated' : $best['threshold'],
                    $this->rate($best['accuracy'] ?? null),
                    $this->rate($best['precision'] ?? null),
                    $this->rate($best['recall'] ?? null),
                    $this->rate($best['f1'] ?? null),
                    $hard === null ? self::EMPTY : "{$hard['count']}/{$hard['of']}",
                    $report['threshold'] ?? self::EMPTY,
                    $this->rate($report['at_configured_threshold']['recall'] ?? null),
                    $configured === null ? self::EMPTY : "{$configured['count']}/{$configured['of']}",
                    $this->rate($report['reason_recall']['overall']['rate']),
                    $report['latency_ms']['p50'] ?? self::EMPTY,
                    $report['latency_ms']['p95'] ?? self::EMPTY,
                ];
            }, array_values($reports)),
        );

        try {
            $writer->writeFile($directory, self::FILE, [
                'metadata' => [
                    'generated_at' => $startedAt->toIso8601String(),
                    'set_version' => ModerationEvalSet::VERSION,
                    'items' => count($items),
                    'provider' => $agent->providerName(),
                    'models' => $models,
                    'prompt_version' => $agent->promptVersion(),
                ],
                'summary' => $reports,
                'results' => $results,
            ], $force);
        } catch (RagEvalResultException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Results: '.$directory.'/'.self::FILE);

        return self::SUCCESS;
    }

    private function moderationInput(ModerationSetItem $item): ModerationInput
    {
        return new ModerationInput($item->title, (float) $item->priceEur, $item->make, $item->model, $item->year, $item->mileageKm, $item->city, $item->description);
    }

    private function rate(?float $rate): string
    {
        return $rate === null ? self::EMPTY : number_format($rate, 3);
    }

    private function directory(string $date): string
    {
        return $this->absolute((string) $this->option('output-dir') ?: "bench/results/moderation/{$date}");
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
