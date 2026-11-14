<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\NewPlaygroundRun;
use App\DTOs\PlaygroundRunRecord;
use App\DTOs\PlaygroundRunSummary;
use App\DTOs\PlaygroundStatistics;

interface PlaygroundRunRepositoryInterface
{
    public function create(NewPlaygroundRun $run): PlaygroundRunRecord;

    /**
     * The run with its results in order, or null when the id is unknown or is not a UUID.
     */
    public function find(string $id): ?PlaygroundRunRecord;

    /**
     * The latest runs, newest first, without their outputs.
     *
     * @return list<PlaygroundRunSummary>
     */
    public function recent(int $limit): array;

    /**
     * Run, prompt and version counts and the average total time of a two-model run on local providers.
     */
    public function statistics(): PlaygroundStatistics;
}
