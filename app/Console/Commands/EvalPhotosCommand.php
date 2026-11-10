<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\AiProviders;
use App\Ai\OllamaVisionModels;
use App\Ai\PhotoVisionAgent;
use App\Contracts\PhotoEvalRepositoryInterface;
use App\DTOs\PhotoEvalOutcome;
use App\DTOs\PhotoEvalSample;
use App\Exceptions\ProviderNotConfiguredException;
use App\Exceptions\RagEvalOptionException;
use App\Exceptions\RagEvalResultException;
use App\Services\PhotoEvalScorer;
use App\Services\PhotoEvalService;
use App\Services\RagEvalResultWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use JsonException;

#[Signature('photos:eval
    {--photos=50 : How many imported photos to measure, taken in sha256 order}
    {--models=gemma4:12b,qwen3.5:9b : Comma-separated vision models of the photo-vision provider}
    {--force : Overwrite the result file of the same UTC day}')]
#[Description('Measure the vision extraction: validity, attempts, latency, body type agreement and colour presence per model')]
final class EvalPhotosCommand extends Command
{
    private const EMPTY = '—';

    public function handle(
        PhotoEvalRepositoryInterface $photos,
        PhotoEvalService $evaluator,
        PhotoEvalScorer $scorer,
        AiProviders $providers,
        OllamaVisionModels $visionModels,
        RagEvalResultWriter $writer,
    ): int {
        $count = filter_var($this->option('photos'), FILTER_VALIDATE_INT);

        if ($count === false || $count < 1) {
            $this->error('--photos must be a positive whole number.');

            return self::INVALID;
        }

        $models = array_values(array_unique(array_filter(array_map(trim(...), explode(',', (string) $this->option('models'))))));

        if ($models === []) {
            $this->error('At least one model is needed.');

            return self::INVALID;
        }

        $configured = $providers->configured('photo-vision');

        try {
            $providers->forFeature('photo-vision');
        } catch (ProviderNotConfiguredException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($models as $model) {
            if ($model !== $configured->model && $configured->driver === 'ollama' && ! $visionModels->has($model)) {
                $this->error("{$model} is not a vision model on this Ollama.");

                return self::FAILURE;
            }
        }

        $startedAt = Date::now('UTC');
        $force = (bool) $this->option('force');

        try {
            $directory = $writer->contain(rtrim((string) config('ai.docs.eval_results_root'), '/').'/photo-vision/'.$startedAt->format('Y-m-d'));
            $writer->assertWritableFile($directory, 'run.json', $force);
        } catch (RagEvalOptionException|RagEvalResultException $e) {
            $this->error($e->getMessage());

            return $e instanceof RagEvalResultException ? self::INVALID : self::FAILURE;
        }

        $available = $photos->countPhotos();

        if ($available < $count) {
            $this->error("Only {$available} photos are imported; {$count} are needed. Run photos:import or lower --photos.");

            return self::FAILURE;
        }

        $sample = $photos->sample($count);
        $reports = [];
        $results = [];

        foreach ($models as $model) {
            $this->line("Extracting with {$model}: ".count($sample).' photos');
            $outcomes = [];

            $this->withProgressBar($sample, function (PhotoEvalSample $photo) use ($evaluator, $model, &$outcomes): void {
                $outcomes[] = $evaluator->evaluate($photo, $model);
            });
            $this->newLine(2);

            $reports[] = $scorer->score($model, $outcomes);

            foreach ($outcomes as $outcome) {
                $results[] = $this->resultRow($model, $outcome);
            }
        }

        $this->table(
            ['Model', 'Valid 1st', 'Valid after retries', 'Mean attempts', 'p50 ms', 'p95 ms', 'Body agreement', 'Colour'],
            array_map(fn (array $report): array => [
                $report['model'],
                number_format($report['valid_first_attempt']['rate'], 3),
                number_format($report['valid_after_retries']['rate'], 3),
                $report['mean_attempts'],
                $report['latency_ms']['p50'] ?? self::EMPTY,
                $report['latency_ms']['p95'] ?? self::EMPTY,
                "{$report['body_type_agreement']['agreed']}/{$report['body_type_agreement']['compared']}",
                number_format($report['colour_presence']['rate'], 3),
            ], $reports),
        );

        $agent = new PhotoVisionAgent;

        $data = [
            'metadata' => [
                'generated_at' => $startedAt->toIso8601String(),
                'photos' => count($sample),
                'sample' => 'imported photos ordered by sha256, first '.count($sample),
                'models' => $models,
                'provider' => $agent->providerName(),
                'prompt_version' => $agent->promptVersion(),
            ],
            'models' => $reports,
            'results' => $results,
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

    /**
     * @return array<string, mixed>
     */
    private function resultRow(string $model, PhotoEvalOutcome $outcome): array
    {
        return [
            'photo_id' => $outcome->photoId,
            'model' => $model,
            'valid' => $outcome->isValid(),
            'attempts' => $outcome->attempts,
            'latency_ms' => $outcome->latencyMs,
            'expected_body_type' => $outcome->expectedBodyType,
            'body_type' => $outcome->data['body_type'] ?? null,
            'invocation_ids' => $outcome->invocationIds,
        ];
    }
}
