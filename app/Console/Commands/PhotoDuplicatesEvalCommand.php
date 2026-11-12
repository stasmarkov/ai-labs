<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\AiProviders;
use App\Ai\OllamaVisionModels;
use App\Ai\PhotoBestPickAgent;
use App\Ai\PhotoEncoderClient;
use App\DTOs\DuplicateEvalOutcome;
use App\DTOs\DuplicateEvalPhoto;
use App\Exceptions\ProviderNotConfiguredException;
use App\Exceptions\RagEvalOptionException;
use App\Exceptions\RagEvalResultException;
use App\Services\DuplicateEvalScorer;
use App\Services\DuplicateEvalService;
use App\Services\RagEvalResultWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use JsonException;

#[Signature('photos:duplicates-eval
    {--models=gemma4:12b,qwen3.5:9b : Comma-separated vision models of the photo-vision provider that pick the best photo}
    {--no-judge : Calibrate the cutoff only, without asking a model}
    {--force : Overwrite the result file of the same UTC day}')]
#[Description('Calibrate the duplicate cutoff on generated copies (recall per kind, precision) and measure the best-photo pick per model')]
final class PhotoDuplicatesEvalCommand extends Command
{
    public function handle(
        DuplicateEvalService $evaluator,
        DuplicateEvalScorer $scorer,
        PhotoEncoderClient $encoder,
        AiProviders $providers,
        OllamaVisionModels $visionModels,
        RagEvalResultWriter $writer,
    ): int {
        $judge = ! $this->option('no-judge');
        $models = array_values(array_unique(array_filter(array_map(trim(...), explode(',', (string) $this->option('models'))))));

        if ($judge && $models === []) {
            $this->error('At least one model is needed, or pass --no-judge.');

            return self::INVALID;
        }

        if ($judge) {
            try {
                $configured = $providers->configured('photo-vision');
                $providers->forFeature('photo-vision');
            } catch (ProviderNotConfiguredException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $startedAt = Date::now('UTC');
        $force = (bool) $this->option('force');

        try {
            $directory = $writer->contain(rtrim((string) config('ai.docs.eval_results_root'), '/').'/photo-duplicates/'.$startedAt->format('Y-m-d'));
            $writer->assertWritableFile($directory, 'run.json', $force);
        } catch (RagEvalOptionException|RagEvalResultException $e) {
            $this->error($e->getMessage());

            return $e instanceof RagEvalResultException ? self::INVALID : self::FAILURE;
        }

        $label = $encoder->label();
        $calibration = $evaluator->calibrate($label);

        if ($calibration['true_pairs'] === 0) {
            $this->error("No generated copy is embedded with {$label}. Run photos:make-duplicates, then photos:embed.");

            return self::FAILURE;
        }

        $chosen = $calibration['chosen'];
        $this->table(
            ['Cutoff', 'Pairs', 'True', 'False', 'Precision', 'Recall', 'F1'],
            array_map(fn (array $row): array => [
                $row['threshold'], $row['pairs'], $row['true_pairs'], $row['false_pairs'],
                number_format($row['precision'], 3), number_format($row['recall'], 3), number_format($row['f1'], 3),
            ], $calibration['curve']),
        );
        $this->info("Chosen cutoff {$chosen['threshold']} ({$this->ruleText($chosen['rule'])}).");
        $this->line("Clustered {$calibration['timing']['photos']} photos in {$calibration['timing']['clustering_ms']} ms.");

        $classified = $scorer->classifyGroups($calibration['groups'], $calibration['photos']);
        $this->line(count($calibration['groups']).' groups at the cutoff: '.count($classified['judged']).' with one original and its copies, '
            .array_sum($classified['skipped']).' left out.');

        $judging = ['models' => [], 'skipped' => []];

        if ($judge) {
            [$usable, $judging['skipped']] = $this->usableModels($models, $configured->model, $configured->driver, $visionModels);

            foreach ($judging['skipped'] as $skip) {
                $this->warn("Skipping {$skip['model']}: {$skip['reason']}");
            }

            foreach ($usable as $model) {
                $this->line("Judging with {$model}: ".count($classified['judged']).' groups');
                $outcomes = [];

                $this->withProgressBar($classified['judged'], function (array $members) use ($evaluator, $model, &$outcomes): void {
                    $original = array_values(array_filter($members, fn (DuplicateEvalPhoto $photo): bool => $photo->isOriginal()))[0];
                    $outcomes[] = $evaluator->judge($members, $original->id, $model);
                });
                $this->newLine(2);

                $judging['models'][] = ['outcomes' => $outcomes, ...$scorer->pickAccuracy($model, $outcomes)];
            }

            $this->table(
                ['Model', 'Groups', 'Picked original', 'Valid 1st', 'Valid after retries', 'p50 ms', 'p95 ms'],
                array_map(fn (array $report): array => [
                    $report['model'], $report['groups'],
                    number_format($report['picked_original']['rate'], 3),
                    number_format($report['valid_first_attempt']['rate'], 3),
                    number_format($report['valid_after_retries']['rate'], 3),
                    $report['latency_ms']['p50'] ?? '—', $report['latency_ms']['p95'] ?? '—',
                ], $judging['models']),
            );
        }

        $agent = new PhotoBestPickAgent;
        $data = [
            'metadata' => [
                'generated_at' => $startedAt->toIso8601String(),
                'label' => $label,
                'photos' => count($calibration['photos']),
                'originals' => count(array_filter($calibration['photos'], fn (DuplicateEvalPhoto $photo): bool => $photo->isOriginal())),
                'copies' => count(array_filter($calibration['photos'], fn (DuplicateEvalPhoto $photo): bool => ! $photo->isOriginal())),
                'true_pairs' => $calibration['true_pairs'],
                'thresholds' => DuplicateEvalService::THRESHOLDS,
                'candidates' => (int) config('ai.photo_duplicates.candidates'),
                'max_group' => (int) config('ai.photo_duplicates.max_group'),
                'prompt_version' => $agent->promptVersion(),
                'models' => $models,
                'judged' => $judge,
                'ground_truth' => 'a pair is true when both photos come from one original; recall per kind counts original-with-copy pairs',
            ],
            'curve' => $calibration['curve'],
            'cutoff' => $chosen,
            'groups' => [
                'count' => count($calibration['groups']),
                'split' => count(array_filter($calibration['groups'], fn ($group): bool => $group->split)),
                'judged' => count($classified['judged']),
                'left_out' => $classified['skipped'],
            ],
            'clustering' => $calibration['timing'],
            'judging' => [
                'skipped' => $judging['skipped'],
                'models' => array_map(fn (array $report): array => array_diff_key($report, ['outcomes' => true]), $judging['models']),
                'results' => array_merge([], ...array_map(
                    fn (array $report): array => array_map(fn (DuplicateEvalOutcome $outcome): array => [
                        'model' => $report['model'],
                        'original_id' => $outcome->originalId,
                        'best_photo_id' => $outcome->bestPhotoId,
                        'picked_original' => $outcome->pickedOriginal(),
                        'attempts' => $outcome->attempts,
                        'latency_ms' => $outcome->latencyMs,
                        'invocation_ids' => $outcome->invocationIds,
                    ], $report['outcomes']),
                    $judging['models'],
                )),
            ],
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
     * A model other than the configured one is used only when this Ollama has it with vision; the rest are recorded as skipped.
     *
     * @param  list<string>  $models
     * @return array{0: list<string>, 1: list<array{model: string, reason: string}>}
     */
    private function usableModels(array $models, string $configuredModel, string $driver, OllamaVisionModels $visionModels): array
    {
        $usable = [];
        $skipped = [];

        foreach ($models as $model) {
            if ($model !== $configuredModel && $driver === 'ollama' && ! $visionModels->has($model)) {
                $skipped[] = ['model' => $model, 'reason' => 'not installed as a vision model on this Ollama'];

                continue;
            }

            $usable[] = $model;
        }

        return [$usable, $skipped];
    }

    private function ruleText(string $rule): string
    {
        return $rule === DuplicateEvalScorer::RULE_CLEAN
            ? 'the highest cutoff with recall of at least '.DuplicateEvalScorer::MIN_RECALL.' and no false pair'
            : 'no cutoff gives recall of at least '.DuplicateEvalScorer::MIN_RECALL.' with no false pair, so the best F1';
    }
}
