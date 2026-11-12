<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\PhotoEncoderClient;
use App\DTOs\SimilarEvalPhoto;
use App\Exceptions\PhotoEncoderException;
use App\Exceptions\RagEvalOptionException;
use App\Exceptions\RagEvalResultException;
use App\Services\RagEvalResultWriter;
use App\Services\SimilarEvalService;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use JsonException;

#[Signature('photos:similar-eval
    {--k=8 : How many results count per query}
    {--hybrid : Sweep the image weight of the hybrid ranking and write hybrid.json instead of run.json}
    {--force : Overwrite the result file of the same UTC day}')]
#[Description('Measure similar photos: precision for make and model and for make, coverage, latency with and without the cache, throughput')]
final class SimilarPhotosEvalCommand extends Command
{
    public function handle(SimilarEvalService $evaluator, RagEvalResultWriter $writer, PhotoEncoderClient $encoder): int
    {
        $k = filter_var($this->option('k'), FILTER_VALIDATE_INT);

        if ($k === false || $k < 1) {
            $this->error('--k must be a positive whole number.');

            return self::INVALID;
        }

        $startedAt = Date::now('UTC');
        $force = (bool) $this->option('force');
        $hybrid = (bool) $this->option('hybrid');
        $file = $hybrid ? 'hybrid.json' : 'run.json';

        try {
            $directory = $writer->contain(rtrim((string) config('ai.docs.eval_results_root'), '/').'/photo-similar/'.$startedAt->format('Y-m-d'));
            $writer->assertWritableFile($directory, $file, $force);
        } catch (RagEvalOptionException|RagEvalResultException $e) {
            $this->error($e->getMessage());

            return $e instanceof RagEvalResultException ? self::INVALID : self::FAILURE;
        }

        $photos = $evaluator->photos();

        if ($photos === []) {
            $this->error("No photo is embedded with {$encoder->label()}. Run photos:embed first.");

            return self::FAILURE;
        }

        $this->line('Querying '.count($photos).' photos');

        if ($hybrid) {
            return $this->hybrid($evaluator, $writer, $encoder, $photos, $k, $startedAt, $directory, $force);
        }

        $result = $evaluator->evaluate($photos, $k);
        $metrics = $result['metrics'];

        $this->table(
            ['Photos', "P@{$k} make+model", "P@{$k} make", 'Coverage', 'Uncached p50/p95 ms', 'Cached p50/p95 ms', 'Photos/s'],
            [[
                $metrics['queries'],
                number_format($metrics['precision_make_model'], 3),
                number_format($metrics['precision_make'], 3),
                number_format($metrics['coverage'], 3),
                "{$result['latency']['uncached']['p50_ms']} / {$result['latency']['uncached']['p95_ms']}",
                "{$result['latency']['cached']['p50_ms']} / {$result['latency']['cached']['p95_ms']}",
                $result['throughput']['photos_per_second'] ?? '—',
            ]],
        );

        $data = [
            'metadata' => [
                'generated_at' => $startedAt->toIso8601String(),
                'label' => $encoder->label(),
                'photos' => count($photos),
                'k' => $k,
                'min_score' => (float) config('ai.similar_photos.min_score'),
                'near_duplicate_cutoff' => (float) config('ai.similar_photos.near_duplicate_cutoff'),
                'ground_truth' => 'make and model of the query photo\'s listing',
            ],
            ...$result,
        ];

        try {
            $writer->writeFile($directory, $file, $data, $force);
        } catch (RagEvalResultException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Results: {$directory}/{$file}");

        return self::SUCCESS;
    }

    /**
     * @param  list<SimilarEvalPhoto>  $photos
     */
    private function hybrid(SimilarEvalService $evaluator, RagEvalResultWriter $writer, PhotoEncoderClient $encoder, array $photos, int $k, CarbonInterface $startedAt, string $directory, bool $force): int
    {
        try {
            $result = $evaluator->evaluateHybrid($photos, $k);
        } catch (PhotoEncoderException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $best = $result['best'];
        $baseline = $result['baseline'];

        $this->table(
            ['Run', 'w', 'min_score', "P@{$k} make+model", 'Coverage', 'Text pairs'],
            [
                ['image only', 1.0, $baseline['min_score'], number_format($baseline['precision_make_model'], 3), number_format($baseline['coverage'], 3), '—'],
                ...array_map(fn (array $row): array => ['caption', $row['w'], $row['min_score'], number_format($row['precision_make_model'], 3), number_format($row['coverage'], 3), number_format($row['text_pair_share'], 3)], $result['sweep']),
                ...array_map(fn (array $row): array => ['title (leaks ground truth)', $row['w'], $row['min_score'], number_format($row['precision_make_model'], 3), number_format($row['coverage'], 3), '—'], $result['title']['sweep']),
            ],
        );
        $this->line($best['beats_baseline']
            ? "Best w {$best['w']} with min_score {$best['min_score']}: captions help."
            : 'No w beats image only by 0.01: the default stays 1.0.');
        $this->line("Uncached p95 at w {$result['latency']['w']}: {$result['latency']['uncached']['p95_ms']} ms ("
            .($result['latency']['within_budget'] ? 'within' : 'over').' the budget of '.SimilarEvalService::LATENCY_BUDGET_MS.' ms).');

        $data = [
            'metadata' => [
                'generated_at' => $startedAt->toIso8601String(),
                'label' => $encoder->label(),
                'photos' => count($photos),
                'k' => $k,
                'thresholds' => SimilarEvalService::THRESHOLDS,
                'weights' => SimilarEvalService::WEIGHTS,
                'near_duplicate_cutoff' => (float) config('ai.similar_photos.near_duplicate_cutoff'),
                'ground_truth' => 'make and model of the query photo\'s listing',
            ],
            ...$result,
        ];

        try {
            $writer->writeFile($directory, 'hybrid.json', $data, $force);
        } catch (RagEvalResultException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Results: {$directory}/hybrid.json");

        return self::SUCCESS;
    }
}
