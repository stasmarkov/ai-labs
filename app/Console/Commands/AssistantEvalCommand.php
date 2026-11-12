<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\BuyerAssistantAgent;
use App\Contracts\AssistantTurnRunnerInterface;
use App\DTOs\AssistantEvalQuestion;
use App\DTOs\AssistantEvalTurn;
use App\Exceptions\ProviderNotConfiguredException;
use App\Exceptions\RagEvalOptionException;
use App\Exceptions\RagEvalResultException;
use App\Exceptions\RagEvalSetException;
use App\Services\AssistantEvalScorer;
use App\Services\AssistantEvalSet;
use App\Services\RagEvalResultWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use JsonException;

#[Signature('assistant:eval
    {--models= : Comma-separated models (default: the configured assistant model)}
    {--questions= : Question set file (default: bench/assistant/questions.v1.jsonl)}
    {--output-dir= : Directory for run.json, replacing bench/results/assistant/<UTC date>}
    {--force : Overwrite an existing run.json}')]
#[Description('Measure the buyer assistant: tool-call precision and recall, argument validity, repairs, filter accuracy, steps, hallucinated and unlinked listing ids and latency per model')]
final class AssistantEvalCommand extends Command
{
    private const EMPTY = '—';

    private const FILE = 'run.json';

    public function handle(AssistantEvalSet $set, AssistantTurnRunnerInterface $runner, AssistantEvalScorer $scorer, RagEvalResultWriter $writer): int
    {
        $startedAt = Date::now('UTC');
        $models = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $this->option('models'))))));

        try {
            $directory = $writer->contain($this->directory($startedAt->format('Y-m-d')));
            $force = (bool) $this->option('force');
            $writer->assertWritableFile($directory, self::FILE, $force);
            $questions = $set->load($this->option('questions') ? $this->absolute((string) $this->option('questions')) : null);
        } catch (RagEvalOptionException|RagEvalResultException|RagEvalSetException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $agent = new BuyerAssistantAgent;
        $models = $models === [] ? [$agent->modelName()] : $models;
        $reports = [];
        $results = [];

        try {
            foreach ($models as $model) {
                $this->line("Asking {$model}: ".count($questions).' questions');
                $turns = array_map(fn (AssistantEvalQuestion $question): AssistantEvalTurn => $runner->run($question, $model), $questions);
                $reports[] = $scorer->score($model, $questions, $turns);

                foreach ($questions as $index => $question) {
                    $results[] = $this->resultRow($scorer, $model, $question, $turns[$index]);
                }
            }
        } catch (ProviderNotConfiguredException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Model', 'Precision', 'Recall', 'Valid 1st', 'Repaired', 'Filters', 'Steps mean/max', 'Hallucinated', 'Unlinked', 'Unsupported said', 'p50 ms', 'p95 ms'],
            array_map(fn (array $report): array => [
                $report['model'],
                $this->rate($report['tool_call_precision']['rate']),
                $this->rate($report['tool_call_recall']['rate']),
                $this->rate($report['argument_validity_first_try']['rate']),
                $this->rate($report['repair_success']['rate']),
                $this->rate($report['filter_accuracy']['rate']),
                "{$report['steps']['mean']}/{$report['steps']['max']}",
                "{$report['answers_citing_only_returned_ids']['hallucinated']}/{$report['answers_citing_only_returned_ids']['of']}",
                "{$report['unlinked_mentions']['count']}/{$report['unlinked_mentions']['of']}",
                "{$report['unsupported_mentioned']['count']}/{$report['unsupported_mentioned']['of']}",
                $report['latency_ms']['p50'] ?? self::EMPTY,
                $report['latency_ms']['p95'] ?? self::EMPTY,
            ], $reports),
        );

        try {
            $writer->writeFile($directory, self::FILE, [
                'metadata' => [
                    'generated_at' => $startedAt->toIso8601String(),
                    'question_set_version' => AssistantEvalSet::VERSION,
                    'questions' => count($questions),
                    'models' => $models,
                    'provider' => $agent->providerName(),
                    'prompt_version' => $agent->promptVersion(),
                ],
                'models' => $reports,
                'results' => $results,
            ], $force);
        } catch (RagEvalResultException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Results: '.$directory.'/'.self::FILE);

        $over = array_filter($reports, fn (array $report): bool => ! $report['steps']['within_limit']);

        if ($over !== []) {
            $this->error('A question used more tool steps than the limit of '.$reports[0]['steps']['limit'].': '.implode(', ', array_column($over, 'model')).'.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function resultRow(AssistantEvalScorer $scorer, string $model, AssistantEvalQuestion $question, AssistantEvalTurn $turn): array
    {
        return [
            'id' => $question->id,
            'model' => $model,
            'status' => $turn->status,
            'expects_tool' => $question->expectsTool,
            'steps' => count($turn->calls),
            'calls' => array_map(fn ($call): array => ['arguments' => $call->arguments, 'outcome' => $call->outcome, 'returned_ids' => $call->returnedIds], $turn->calls),
            'hallucinated_ids' => $scorer->hallucinatedIds($turn),
            'unlinked_mentions' => $scorer->unlinkedMentions($turn),
            'unsupported_mentioned' => $question->unsupported === [] ? null : $scorer->mentionsUnsupported($question, $turn),
            'latency_ms' => $turn->latencyMs,
        ];
    }

    private function rate(?float $rate): string
    {
        return $rate === null ? self::EMPTY : number_format($rate, 3);
    }

    private function directory(string $date): string
    {
        return $this->absolute((string) $this->option('output-dir') ?: "bench/results/assistant/{$date}");
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
