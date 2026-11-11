<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\PhotoEncoderClient;
use App\Exceptions\RagEvalOptionException;
use App\Exceptions\RagEvalResultException;
use App\Services\RagEvalResultWriter;
use App\Services\SimilarEvalService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use JsonException;

#[Signature('photos:similar-eval
    {--k=8 : How many results count per query}
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

        try {
            $directory = $writer->contain(rtrim((string) config('ai.docs.eval_results_root'), '/').'/photo-similar/'.$startedAt->format('Y-m-d'));
            $writer->assertWritableFile($directory, 'run.json', $force);
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
            $writer->writeFile($directory, 'run.json', $data, $force);
        } catch (RagEvalResultException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Results: {$directory}/run.json");

        return self::SUCCESS;
    }
}
