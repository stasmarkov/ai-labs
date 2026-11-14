<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ResearchRunRepositoryInterface;
use App\DTOs\ResearchRunRecord;
use App\DTOs\ResearchStepRecord;
use App\Enums\ResearchRunStatus;
use App\Jobs\ExecuteResearchRunJob;
use App\Support\ResearchReportHtml;
use Illuminate\Support\Str;

/**
 * What the lab's research endpoints and page do with a run: start one on the queue and show a stored one, with the
 * report rendered safely.
 */
final readonly class ResearchRunService
{
    public const RECENT_RUNS = 20;

    public function __construct(private ResearchRunRepositoryInterface $runs, private ResearchRunner $runner) {}

    /**
     * Stores a run for the topic and queues it. A topic the guard refuses is stored as `refused` and nothing is queued.
     */
    public function start(string $topic, ?string $provider): ResearchRunRecord
    {
        $run = $this->runner->start($topic, $provider);

        if ($run->status === ResearchRunStatus::Running) {
            ExecuteResearchRunJob::dispatch($run->id);
        }

        return $run;
    }

    /**
     * The guard pattern a refused run matched, kept in its error as "input:<guard>:<pattern>".
     */
    public function refusedPattern(ResearchRunRecord $run): ?string
    {
        return $run->status === ResearchRunStatus::Refused && $run->error !== null ? Str::afterLast($run->error, ':') : null;
    }

    /**
     * @return array<string, mixed>|null null when the id is unknown
     */
    public function find(string $id): ?array
    {
        $run = $this->runs->find($id);

        if ($run === null) {
            return null;
        }

        $aiRuns = $this->runs->aiRunsById(array_values(array_filter(array_map(fn (ResearchStepRecord $step): ?int => $step->aiRunId, $run->steps))));
        $stale = $run->isStale();

        return [
            'id' => $run->id,
            'topic' => $run->topic,
            'status' => ($stale ? ResearchRunStatus::Failed : $run->status)->value,
            'error' => $stale ? ResearchRunner::ERROR_STALE : $run->error,
            'provider' => $run->provider,
            'model' => $run->model,
            'sub_questions' => $run->subQuestions,
            'steps' => array_map(fn (ResearchStepRecord $step): array => [
                'position' => $step->position,
                'phase' => $step->phase->value,
                'sub_question_index' => $step->subQuestionIndex,
                'status' => $step->status->value,
                'error' => $step->error,
                'duration_ms' => $step->durationMs,
                'input' => $step->input,
                'output' => $step->output,
                'ai_run' => $step->aiRunId === null ? null : ($aiRuns[$step->aiRunId] ?? null)?->toArray(),
            ], $run->steps),
            'report' => $run->report,
            'report_html' => $run->report === null ? null : $this->html($run->report, $run->sources ?? []),
            'sources' => $run->sources ?? [],
            'counts' => [
                'model_calls' => $run->modelCalls,
                'steps' => $run->stepCount,
                'failed_notes' => $run->failedNotes,
                'dropped_chunk_ids' => $run->droppedChunkIds,
                'unknown_citations' => $run->unknownCitations,
                'tokens_in' => $run->tokensIn,
                'tokens_out' => $run->tokensOut,
            ],
            'started_at' => $run->startedAt?->toIso8601String(),
            'finished_at' => $run->finishedAt?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{id: string, topic: string, status: string, model: string, started_at: string|null}>
     */
    public function recent(): array
    {
        return array_map(fn (ResearchRunRecord $run): array => [
            'id' => $run->id,
            'topic' => $run->topic,
            'status' => ($run->isStale() ? ResearchRunStatus::Failed : $run->status)->value,
            'model' => $run->model,
            'started_at' => $run->startedAt?->toIso8601String(),
        ], $this->runs->recent(self::RECENT_RUNS));
    }

    /**
     * The report as HTML for the page: see ResearchReportHtml. Each `[n]` that names a source links to that source's entry.
     *
     * @param  list<array<string, mixed>>  $sources
     */
    private function html(string $report, array $sources): string
    {
        return ResearchReportHtml::render($report, array_map(fn (array $source): int => (int) $source['n'], $sources));
    }
}
