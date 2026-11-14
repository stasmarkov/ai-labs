<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\NewResearchRun;
use App\DTOs\NewResearchStep;
use App\DTOs\ResearchRunOutcome;
use App\DTOs\ResearchRunRecord;
use App\DTOs\ResearchRunStatistics;
use App\DTOs\ResearchStepAiRun;
use App\DTOs\ResearchStepRecord;

interface ResearchRunRepositoryInterface
{
    public function create(NewResearchRun $run): ResearchRunRecord;

    /**
     * Stores the step at the next position of the run and counts it on the run.
     */
    public function addStep(string $runId, NewResearchStep $step): ResearchStepRecord;

    /**
     * The id of the `ai_runs` row logged for a model call, or null when none was written.
     */
    public function aiRunIdOf(string $invocationId): ?int;

    /**
     * The logged model calls by `ai_runs` id; an id without a row is left out.
     *
     * @param  list<int>  $aiRunIds
     * @return array<int, ResearchStepAiRun>
     */
    public function aiRunsById(array $aiRunIds): array;

    /**
     * Stores the plan's sub-questions on a run that is still going.
     *
     * @param  list<string>  $subQuestions
     */
    public function setSubQuestions(string $runId, array $subQuestions): void;

    /**
     * Ends a run that is still `running`. Does nothing for a run that already ended, and returns false then.
     */
    public function finish(string $runId, ResearchRunOutcome $outcome): bool;

    /**
     * Marks a run that is still `running` as failed with the error code, keeping its counts, sources and sub-questions as stored.
     * Does nothing for a run that already ended, and returns false then.
     */
    public function fail(string $runId, string $error): bool;

    /**
     * The run with its steps in order, or null when the id is unknown or is not a UUID.
     */
    public function find(string $id): ?ResearchRunRecord;

    /**
     * The latest runs, newest first, without their steps.
     *
     * @return list<ResearchRunRecord>
     */
    public function recent(int $limit): array;

    /**
     * Run counts by status and the averages of the runs that executed.
     */
    public function statistics(): ResearchRunStatistics;
}
