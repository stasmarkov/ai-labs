<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\PhotoEncoderClient;
use App\Exceptions\IndexNotBuiltException;
use App\Exceptions\PhotoSearchRefusedException;
use App\Exceptions\PhotoSearchUnavailableException;
use App\Exceptions\RagEvalOptionException;
use App\Exceptions\RagEvalResultException;
use App\Exceptions\RagEvalSetException;
use App\Services\PhotoSearchEvalService;
use App\Services\PhotoSearchEvalSet;
use App\Services\PhotoTextSearchService;
use App\Services\RagEvalResultWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use JsonException;

#[Signature('photos:search-eval
    {--k=8 : How many results count per query}
    {--force : Overwrite the result file of the same UTC day}')]
#[Description('Measure text-to-photo search: precision, MRR and coverage over a labelled query set, the threshold curve, the bare query against the template, latency')]
final class PhotoSearchEvalCommand extends Command
{
    public function handle(PhotoSearchEvalSet $set, PhotoSearchEvalService $evaluator, RagEvalResultWriter $writer, PhotoEncoderClient $encoder, PhotoTextSearchService $search): int
    {
        $k = filter_var($this->option('k'), FILTER_VALIDATE_INT);

        $limit = (int) config('ai.photo_search.limit');

        if ($k === false || $k < 1 || $k > $limit) {
            $this->error("--k must be a whole number from 1 to {$limit}, the number of results a search returns.");

            return self::INVALID;
        }

        $startedAt = Date::now('UTC');
        $force = (bool) $this->option('force');

        try {
            $directory = $writer->contain(rtrim((string) config('ai.docs.eval_results_root'), '/').'/photo-search/'.$startedAt->format('Y-m-d'));
            $writer->assertWritableFile($directory, 'run.json', $force);
            $queries = $set->load();
        } catch (RagEvalOptionException|RagEvalResultException|RagEvalSetException $e) {
            $this->error($e->getMessage());

            return $e instanceof RagEvalResultException ? self::INVALID : self::FAILURE;
        }

        $embedded = $evaluator->embeddedPhotoIds();

        if ($embedded === []) {
            $this->error("No photo is embedded with {$encoder->label()}. Run photos:embed first.");

            return self::FAILURE;
        }

        $this->line('Searching '.count($queries).' queries twice (template and bare)');

        try {
            $result = $evaluator->evaluate($queries, $embedded, $k);
        } catch (IndexNotBuiltException|PhotoSearchRefusedException|PhotoSearchUnavailableException|ValidationException $e) {
            $this->error('The search failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Variant', 'Min score', "P@{$k}", 'MRR', 'Coverage', 'Uncached p50/p95 ms'],
            array_map(
                fn (string $name): array => [
                    $name,
                    $result['variants'][$name]['compared_at_min_score'] ?? '-',
                    number_format($result['variants'][$name]['metrics']['precision'] ?? 0.0, 3),
                    number_format($result['variants'][$name]['metrics']['mrr'] ?? 0.0, 3),
                    number_format($result['variants'][$name]['metrics']['coverage'] ?? 0.0, 3),
                    $name === 'template' ? "{$result['latency']['uncached']['p50_ms']} / {$result['latency']['uncached']['p95_ms']}" : '',
                ],
                ['template', 'bare'],
            ),
        );

        $data = [
            'metadata' => [
                'generated_at' => $startedAt->toIso8601String(),
                'label' => $encoder->label(),
                'queries' => count($queries),
                'query_set' => 'bench/photo-search/queries.'.PhotoSearchEvalSet::VERSION.'.jsonl',
                'k' => $k,
                'configured_min_score' => $search->minScore(),
                'ground_truth' => 'make, model and body type from the photo\'s listing and the catalogue; colour from the gemma4:12b photo-vision@v1 extraction (model output, not ground truth)',
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
        $this->line('Calibrated min_score: '.($result['chosen_min_score'] ?? 'none').' (set AI_PHOTO_SEARCH_MIN_SCORE to use it)');

        return self::SUCCESS;
    }
}
