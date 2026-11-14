<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\ResearchRunRepositoryInterface;
use App\DTOs\NewResearchRun;
use App\DTOs\NewResearchStep;
use App\DTOs\ResearchRunOutcome;
use App\DTOs\ResearchRunRecord;
use App\DTOs\ResearchRunStatistics;
use App\DTOs\ResearchStepAiRun;
use App\DTOs\ResearchStepRecord;
use App\Enums\ResearchRunStatus;
use App\Models\AiRun;
use App\Models\ResearchRun;
use App\Models\ResearchStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EloquentResearchRunRepository implements ResearchRunRepositoryInterface
{
    public function create(NewResearchRun $run): ResearchRunRecord
    {
        return $this->record(ResearchRun::query()->create([
            'topic' => $run->topic,
            'status' => $run->status->value,
            'error' => $run->error === null ? null : mb_substr($run->error, 0, 500),
            'provider' => $run->provider,
            'model' => $run->model,
            'prompt_versions' => $run->promptVersions,
            'started_at' => now(),
            'finished_at' => $run->status->hasEnded() ? now() : null,
        ])->refresh());
    }

    public function addStep(string $runId, NewResearchStep $step): ResearchStepRecord
    {
        return DB::transaction(function () use ($runId, $step): ResearchStepRecord {
            ResearchRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();

            $position = 1 + (int) ResearchStep::query()->where('research_run_id', $runId)->max('position');

            $stored = ResearchStep::query()->create([
                'research_run_id' => $runId,
                'position' => $position,
                'phase' => $step->phase->value,
                'sub_question_index' => $step->subQuestionIndex,
                'input' => $step->input,
                'output' => $step->output,
                'ai_run_id' => $step->aiRunId,
                'status' => $step->status->value,
                'error' => $step->error === null ? null : mb_substr($step->error, 0, 500),
                'duration_ms' => max(0, $step->durationMs),
            ])->refresh();

            ResearchRun::query()->whereKey($runId)->update(['steps' => $position, 'updated_at' => now()]);

            return $this->stepRecord($stored);
        });
    }

    public function aiRunIdOf(string $invocationId): ?int
    {
        $id = AiRun::query()->where('invocation_id', $invocationId)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function aiRunsById(array $aiRunIds): array
    {
        if ($aiRunIds === []) {
            return [];
        }

        return AiRun::query()
            ->whereIn('id', $aiRunIds)
            ->get(['id', 'invocation_id', 'model', 'tokens_in', 'tokens_out'])
            ->mapWithKeys(fn (AiRun $run): array => [$run->id => new ResearchStepAiRun($run->invocation_id, $run->model, $run->tokens_in, $run->tokens_out)])
            ->all();
    }

    public function setSubQuestions(string $runId, array $subQuestions): void
    {
        ResearchRun::query()->whereKey($runId)->update([
            'sub_questions' => json_encode($subQuestions),
            'updated_at' => now(),
        ]);
    }

    public function finish(string $runId, ResearchRunOutcome $outcome): bool
    {
        $changes = [
            'status' => $outcome->status->value,
            'report' => $outcome->report,
            'sources' => json_encode($outcome->sources),
            'error' => $outcome->error === null ? null : mb_substr($outcome->error, 0, 500),
            'model_calls' => $outcome->modelCalls,
            'failed_notes' => $outcome->failedNotes,
            'dropped_chunk_ids' => $outcome->droppedChunkIds,
            'unknown_citations' => $outcome->unknownCitations,
            'tokens_in' => $outcome->tokensIn,
            'tokens_out' => $outcome->tokensOut,
            'finished_at' => now(),
            'updated_at' => now(),
        ];

        if ($outcome->subQuestions !== null) {
            $changes['sub_questions'] = json_encode($outcome->subQuestions);
        }

        return ResearchRun::query()->whereKey($runId)->where('status', ResearchRunStatus::Running->value)->update($changes) === 1;
    }

    public function fail(string $runId, string $error): bool
    {
        return ResearchRun::query()->whereKey($runId)->where('status', ResearchRunStatus::Running->value)->update([
            'status' => ResearchRunStatus::Failed->value,
            'error' => mb_substr($error, 0, 500),
            'finished_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    public function find(string $id): ?ResearchRunRecord
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $run = ResearchRun::query()->with('researchSteps')->find($id);

        return $run === null ? null : $this->record($run);
    }

    public function recent(int $limit): array
    {
        return ResearchRun::query()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (ResearchRun $run): ResearchRunRecord => $this->record($run, withSteps: false))
            ->all();
    }

    public function statistics(): ResearchRunStatistics
    {
        /** @var array<string, int> $byStatus */
        $byStatus = ResearchRun::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn (mixed $total): int => (int) $total)->all();
        $executed = [ResearchRunStatus::Done->value, ResearchRunStatus::Failed->value];

        $averages = ResearchRun::query()
            ->whereIn('status', $executed)
            ->selectRaw('avg(model_calls) as calls, max(model_calls) as max_calls, avg(steps) as steps, avg(extract(epoch from (finished_at - started_at)) * 1000) as duration_ms')
            ->toBase()
            ->first();

        return new ResearchRunStatistics(
            runs: array_sum($byStatus),
            done: $byStatus[ResearchRunStatus::Done->value] ?? 0,
            failed: $byStatus[ResearchRunStatus::Failed->value] ?? 0,
            refused: $byStatus[ResearchRunStatus::Refused->value] ?? 0,
            running: $byStatus[ResearchRunStatus::Running->value] ?? 0,
            averageModelCalls: round((float) ($averages->calls ?? 0), 1),
            maxModelCalls: (int) ($averages->max_calls ?? 0),
            averageSteps: round((float) ($averages->steps ?? 0), 1),
            averageDurationMs: (int) round((float) ($averages->duration_ms ?? 0)),
            models: ResearchRun::query()->whereIn('status', $executed)->distinct()->orderBy('model')->pluck('model')->all(),
        );
    }

    private function record(ResearchRun $run, bool $withSteps = true): ResearchRunRecord
    {
        return new ResearchRunRecord(
            id: $run->id,
            topic: $run->topic,
            status: $run->status,
            subQuestions: $run->sub_questions,
            report: $run->report,
            sources: $run->sources,
            modelCalls: $run->model_calls,
            stepCount: $run->steps,
            failedNotes: $run->failed_notes,
            droppedChunkIds: $run->dropped_chunk_ids,
            unknownCitations: $run->unknown_citations,
            tokensIn: $run->tokens_in,
            tokensOut: $run->tokens_out,
            provider: $run->provider,
            model: $run->model,
            promptVersions: $run->prompt_versions,
            startedAt: $run->started_at,
            finishedAt: $run->finished_at,
            error: $run->error,
            steps: $withSteps && $run->relationLoaded('researchSteps')
                ? $run->researchSteps->map(fn (ResearchStep $step): ResearchStepRecord => $this->stepRecord($step))->all()
                : [],
        );
    }

    private function stepRecord(ResearchStep $step): ResearchStepRecord
    {
        return new ResearchStepRecord(
            id: $step->id,
            position: $step->position,
            phase: $step->phase,
            subQuestionIndex: $step->sub_question_index,
            input: $step->input,
            output: $step->output,
            aiRunId: $step->ai_run_id,
            status: $step->status,
            error: $step->error,
            durationMs: $step->duration_ms,
            createdAt: $step->created_at,
        );
    }
}
