<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\DocsAnswerAgent;
use App\Ai\RagGraderAgent;
use App\DTOs\RagChunkSettings;
use App\DTOs\RagEvalQuestion;
use App\DTOs\RagModelReport;
use App\DTOs\RagRetrievalResult;
use App\Enums\DocsSearchMode;
use App\Exceptions\DocsAnswerUnavailableException;
use App\Exceptions\DocsSearchUnavailableException;
use App\Exceptions\RagEvalDataException;
use App\Exceptions\RagEvalIndexException;
use App\Exceptions\RagEvalModelException;
use App\Exceptions\RagEvalOptionException;
use App\Exceptions\RagEvalResultException;
use App\Exceptions\RagEvalSetException;
use App\Exceptions\RagGraderUnavailableException;
use App\Services\DocsCorpusVersions;
use App\Services\DocsEmbeddingService;
use App\Services\RagAnswerEvaluator;
use App\Services\RagEvalIndexInspector;
use App\Services\RagEvalResultWriter;
use App\Services\RagEvalSet;
use App\Services\RagRetrievalEvaluator;
use App\Services\ThresholdCalibrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use JsonException;

#[Signature('rag:eval
    {--k=6 : Comma-separated k values for recall@k}
    {--modes=hybrid : Comma-separated retrieval modes: vector, text, hybrid}
    {--questions= : Eval set file (default: bench/rag-eval/questions.v1.jsonl)}
    {--answer : Also run the questions through the chatbot and grade the answers}
    {--models= : Comma-separated answer models (default: the configured text model)}
    {--grader=gemma4:12b : Model that grades the answers}
    {--repeat=1 : Answer runs per model}
    {--label= : Name of the run (default: the UTC time HHMMSS)}
    {--output-dir= : Directory for the result files, replacing bench/results/rag-eval/<UTC date>/<label>}
    {--force : Overwrite an existing label, and run even when the index chunk sizes differ from the config}')]
#[Description('Measure docs retrieval (recall@k, MRR), calibrate the relevance threshold and optionally grade the chatbot answers')]
final class RagEvalCommand extends Command
{
    private const EMPTY = '—';

    public function handle(
        RagEvalSet $set,
        RagEvalIndexInspector $inspector,
        RagRetrievalEvaluator $retrieval,
        ThresholdCalibrator $calibrator,
        RagAnswerEvaluator $answers,
        RagEvalResultWriter $writer,
        DocsEmbeddingService $embedding,
        DocsCorpusVersions $versions,
    ): int {
        $startedAt = Date::now('UTC');
        $force = (bool) $this->option('force');
        $answering = (bool) $this->option('answer');

        $ks = $this->integers((string) $this->option('k'));
        $modes = $this->modes((string) $this->option('modes'));
        $repeat = filter_var($this->option('repeat'), FILTER_VALIDATE_INT);
        $grader = trim((string) $this->option('grader'));
        $models = $this->models();

        if ($ks === null || $modes === null || $repeat === false || $repeat < 1 || $grader === '' || $models === []) {
            $this->error('Need positive integers for --k and --repeat, modes among vector, text and hybrid, and a --grader.');

            return self::INVALID;
        }

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

        try {
            $writer->assertWritable($directory, $force);
            $questions = $set->load($this->option('questions') ? $this->absolute((string) $this->option('questions')) : null);
            $settings = $inspector->inspect();
        } catch (RagEvalResultException|RagEvalSetException|RagEvalIndexException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $settings->matches((int) config('ai.docs.chunk.max_tokens'), (int) config('ai.docs.chunk.merge_below_tokens'))) {
            $this->warn(sprintf(
                'The index was built with chunk sizes %s but the config says %d/%d.',
                $settings->describe(),
                (int) config('ai.docs.chunk.max_tokens'),
                (int) config('ai.docs.chunk.merge_below_tokens'),
            ));

            if (! $force) {
                $this->error('Run docs:index to match the config, or pass --force to measure the index as it is.');

                return self::FAILURE;
            }
        }

        try {
            $result = $retrieval->evaluate($questions, $modes, $ks);
            $calibration = $calibrator->calibrate($questions, $result->topSimilarity, (float) config('ai.docs.answer.min_similarity'));
        } catch (RagEvalOptionException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (RagEvalDataException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (DocsSearchUnavailableException $e) {
            $this->error(RagEvalModelException::embeddingUnavailable($embedding->modelLabel(), $e)->getMessage());

            return self::FAILURE;
        }

        $this->recallTable($result);
        $this->calibrationTable($calibration->toArray());

        $metadata = $this->metadata($startedAt->toIso8601String(), $label, $settings, $embedding->modelLabel(), $versions->repo(), $answering ? $models : [], $answering ? $grader : null, $answering ? $repeat : null);

        $files = [
            'recall.json' => [
                'metadata' => $metadata + ['k' => $ks, 'modes' => array_map(fn (DocsSearchMode $mode): string => $mode->value, $modes)],
                'cells' => array_map(fn ($cell): array => $cell->toArray(), $result->cells),
            ],
            'calibration.json' => [
                'metadata' => $metadata + ['configured_threshold' => (float) config('ai.docs.answer.min_similarity')],
                'questions' => $this->similarities($questions, $result),
                ...$calibration->toArray(),
            ],
        ];

        if ($answering) {
            try {
                $reports = $answers->evaluate($questions, $models, $grader, $repeat, fn (string $model, int $run) => $this->line("Answering with {$model}, run {$run} of {$repeat}"));
            } catch (DocsAnswerUnavailableException $e) {
                $this->error(RagEvalModelException::embeddingUnavailable($embedding->modelLabel(), $e)->getMessage());

                return self::FAILURE;
            } catch (RagEvalModelException|RagGraderUnavailableException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->answersTable($reports);
            $files['answers.json'] = [
                'metadata' => $metadata,
                'models' => array_map(fn (RagModelReport $report): array => $report->toArray(), $reports),
            ];
        }

        try {
            $writer->write($directory, $files, $force);
        } catch (RagEvalResultException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Results: {$directory}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $generatedAt, string $label, RagChunkSettings $settings, string $embeddingModel, array $repo, array $models, ?string $grader, ?int $repeat): array
    {
        return [
            'label' => $label,
            'generated_at' => $generatedAt,
            'eval_set_version' => RagEvalSet::VERSION,
            'chunk_settings' => $settings->toArray(),
            'embedding_model' => $embeddingModel,
            'answer_models' => $models,
            'grader_model' => $grader,
            'repeat' => $repeat,
            'prompt_versions' => [
                'docs_answer' => (new DocsAnswerAgent)->promptVersion(),
                'rag_grader' => (new RagGraderAgent)->promptVersion(),
            ],
            'git_commit' => $repo['commit'],
            'git_dirty' => $repo['dirty'],
        ];
    }

    /**
     * @param  list<RagEvalQuestion>  $questions
     * @return array<string, array{type: string, top_similarity: float}>
     */
    private function similarities(array $questions, RagRetrievalResult $result): array
    {
        $rows = [];

        foreach ($questions as $question) {
            $rows[$question->id] = [
                'type' => $question->type,
                'top_similarity' => round($result->topSimilarity[$question->id] ?? -1.0, 6),
            ];
        }

        return $rows;
    }

    private function recallTable(RagRetrievalResult $result): void
    {
        $this->table(
            ['Mode', 'k', 'Recall', 'MRR', 'Hits', 'Missed'],
            array_map(fn ($cell): array => [
                $cell->mode,
                $cell->k,
                number_format($cell->recall(), 3),
                number_format($cell->mrr, 3),
                "{$cell->hits}/{$cell->questions}",
                implode(', ', $cell->missedIds),
            ], $result->cells),
        );
    }

    /**
     * @param  array{on_topic: array<string, mixed>, off_topic: array<string, mixed>, best: array<string, mixed>, configured: array<string, mixed>}  $calibration
     */
    private function calibrationTable(array $calibration): void
    {
        $this->table(
            ['Group', 'Questions', 'Min', 'Median', 'Max'],
            [
                ['on-topic', $calibration['on_topic']['count'], $calibration['on_topic']['min'], $calibration['on_topic']['median'], $calibration['on_topic']['max']],
                ['off-topic', $calibration['off_topic']['count'], $calibration['off_topic']['min'], $calibration['off_topic']['median'], $calibration['off_topic']['max']],
            ],
        );
        $this->table(
            ['Threshold', 'Value', 'TPR', 'FPR', 'J'],
            [
                ['best (Youden)', $calibration['best']['threshold'], $calibration['best']['tpr'], $calibration['best']['fpr'], $calibration['best']['j']],
                ['configured', $calibration['configured']['threshold'], $calibration['configured']['tpr'], $calibration['configured']['fpr'], $calibration['configured']['j']],
            ],
        );
    }

    /**
     * @param  list<RagModelReport>  $reports
     */
    private function answersTable(array $reports): void
    {
        $runs = max(array_map(fn (RagModelReport $report): int => count($report->runs), $reports));
        $total = $runs > 1 ? "sum of {$runs} runs" : '1 run';

        $this->table(
            ['Model', 'Correctness', 'Groundedness', 'Cited', "Declined ({$total})", "False don't-know ({$total})", "False refusal ({$total})", "Ungraded ({$total})", "Failed ({$total})", 'Latency ms', 'TTFT ms'],
            array_map(function (RagModelReport $report): array {
                $spread = $report->acrossRuns();
                $totals = $report->totals();
                $format = fn (string $key): string => match (true) {
                    $spread[$key] === null => self::EMPTY,
                    count($report->runs) > 1 => sprintf('%.2f (%.2f-%.2f)', $spread[$key]['median'], $spread[$key]['min'], $spread[$key]['max']),
                    default => number_format($spread[$key]['median'], 2),
                };

                return [
                    $report->model,
                    $format('correctness'),
                    $format('groundedness'),
                    $format('citation_rate'),
                    "{$totals['declined_correctly']}/{$totals['off_topic']}",
                    "{$totals['false_dont_know']}/{$totals['on_topic']}",
                    $totals['false_refusal'],
                    $totals['ungraded'],
                    $totals['failed'],
                    $report->medianLatencyMs ?? self::EMPTY,
                    $report->medianTtftMs ?? self::EMPTY,
                ];
            }, $reports),
        );
    }

    private function directory(string $label, string $date): string
    {
        $path = (string) $this->option('output-dir') ?: "bench/results/rag-eval/{$date}/{$label}";

        return $this->absolute($path);
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /**
     * @return list<int>|null
     */
    private function integers(string $value): ?array
    {
        $ks = [];

        foreach (explode(',', $value) as $part) {
            $k = filter_var(trim($part), FILTER_VALIDATE_INT);

            if ($k === false || $k < 1) {
                return null;
            }

            $ks[] = $k;
        }

        return $ks;
    }

    /**
     * @return list<DocsSearchMode>|null
     */
    private function modes(string $value): ?array
    {
        $modes = [];

        foreach (explode(',', $value) as $part) {
            $mode = DocsSearchMode::tryFrom(trim($part));

            if ($mode === null) {
                return null;
            }

            $modes[] = $mode;
        }

        return array_values(array_unique($modes, SORT_REGULAR));
    }

    /**
     * @return list<string>
     */
    private function models(): array
    {
        $models = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('models')))));

        return $models !== [] ? $models : [(new DocsAnswerAgent)->modelName()];
    }
}
