<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\DocsCacheCalibration;
use App\DTOs\DocsCachePair;
use App\Exceptions\DocsCachePairException;
use App\Exceptions\DocsSearchUnavailableException;
use App\Exceptions\RagEvalDataException;
use App\Exceptions\RagEvalOptionException;
use App\Exceptions\RagEvalResultException;
use App\Services\DocsCacheCalibrator;
use App\Services\DocsCachePairScorer;
use App\Services\DocsCachePairSet;
use App\Services\DocsCorpusVersions;
use App\Services\DocsEmbeddingService;
use App\Services\RagEvalResultWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use JsonException;

#[Signature('docs:cache-calibrate
    {--pairs= : Pair set file (default: bench/rag-eval/cache-pairs.v1.jsonl)}
    {--label= : Name of the run (default: the UTC time HHMMSS)}
    {--output-dir= : Directory for the result file, replacing bench/results/docs-cache/<UTC date>/<label>}
    {--force : Overwrite an existing label}')]
#[Description('Measure paraphrase and near-miss similarities and calibrate the docs answer cache threshold')]
final class DocsCacheCalibrateCommand extends Command
{
    public function handle(
        DocsCachePairSet $set,
        DocsCachePairScorer $scorer,
        DocsCacheCalibrator $calibrator,
        RagEvalResultWriter $writer,
        DocsEmbeddingService $embedding,
        DocsCorpusVersions $versions,
    ): int {
        $startedAt = Date::now('UTC');
        $label = trim((string) $this->option('label')) ?: $startedAt->format('His');

        try {
            if (preg_match('/\A[A-Za-z0-9._-]{1,60}\z/', $label) !== 1 || in_array($label, ['.', '..'], true)) {
                throw RagEvalOptionException::badLabel($label);
            }

            $directory = $writer->contain($this->directory($label, $startedAt->format('Y-m-d')));
        } catch (RagEvalOptionException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $configured = (float) config('ai.docs.cache.min_similarity');

        try {
            $writer->assertWritable($directory, (bool) $this->option('force'));
            $pairs = $set->load($this->option('pairs') ? $this->absolute((string) $this->option('pairs')) : null);
            $scores = $scorer->score($pairs);
            $calibration = $calibrator->calibrate(
                $this->similarities($pairs, $scores, true),
                $this->similarities($pairs, $scores, false),
                $configured,
            );
        } catch (RagEvalResultException|DocsCachePairException|RagEvalDataException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (DocsSearchUnavailableException $e) {
            $this->error("The embedding model {$embedding->modelLabel()} is unavailable: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->tables($calibration);

        $repo = $versions->repo();

        try {
            $writer->write($directory, [
                'calibration.json' => [
                    'metadata' => [
                        'label' => $label,
                        'generated_at' => $startedAt->toIso8601String(),
                        'pair_set_version' => DocsCachePairSet::VERSION,
                        'pairs' => count($pairs),
                        'embedding_model' => $embedding->modelLabel(),
                        'configured_threshold' => $configured,
                        'git_commit' => $repo['commit'],
                        'git_dirty' => $repo['dirty'],
                    ],
                    'pairs' => $this->pairRows($pairs, $scores),
                    ...$calibration->toArray(),
                ],
            ], (bool) $this->option('force'));
        } catch (RagEvalResultException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Results: {$directory}");

        return self::SUCCESS;
    }

    /**
     * @param  list<DocsCachePair>  $pairs
     * @param  array<string, float>  $scores
     * @return list<float>
     */
    private function similarities(array $pairs, array $scores, bool $paraphrase): array
    {
        $values = [];

        foreach ($pairs as $pair) {
            if ($pair->isParaphrase() === $paraphrase) {
                $values[] = $scores[$pair->id];
            }
        }

        return $values;
    }

    /**
     * @param  list<DocsCachePair>  $pairs
     * @param  array<string, float>  $scores
     * @return array<string, array{label: string, similarity: float}>
     */
    private function pairRows(array $pairs, array $scores): array
    {
        $rows = [];

        foreach ($pairs as $pair) {
            $rows[$pair->id] = ['label' => $pair->label, 'similarity' => round($scores[$pair->id], 6)];
        }

        return $rows;
    }

    private function tables(DocsCacheCalibration $calibration): void
    {
        $data = $calibration->toArray();

        $this->table(
            ['Label', 'Pairs', 'Min', 'Median', 'Max'],
            [
                ['paraphrase', $data['paraphrase']['count'], $data['paraphrase']['min'], $data['paraphrase']['median'], $data['paraphrase']['max']],
                ['near-miss', $data['near_miss']['count'], $data['near_miss']['min'], $data['near_miss']['median'], $data['near_miss']['max']],
            ],
        );

        $row = fn (string $name, ?array $point): array => $point === null
            ? [$name, 'none', '—', '—', '—']
            : [$name, $point['threshold'], $point['tpr'], $point['fpr'], $point['j']];

        $this->table(
            ['Threshold', 'Value', 'TPR', 'FPR', 'J'],
            [
                $row('best (Youden)', $data['best']),
                $row('zero false hit', $data['zero_false_hit']),
                $row('suggested default', $data['suggested_default']),
                $row('configured', $data['configured']),
            ],
        );

        $this->line("Paraphrases lost at the suggested default: {$calibration->lostParaphrases}");
    }

    private function directory(string $label, string $date): string
    {
        $path = (string) $this->option('output-dir') ?: "bench/results/docs-cache/{$date}/{$label}";

        return $this->absolute($path);
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
