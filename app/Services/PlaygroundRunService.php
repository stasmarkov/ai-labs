<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PlaygroundRunRepositoryInterface;
use App\DTOs\PlaygroundRunRecord;
use App\DTOs\PlaygroundRunRequest;
use App\DTOs\PlaygroundRunSummary;
use App\Exceptions\PlaygroundRunException;
use App\Support\RequestTimeLimit;

/**
 * What the lab's playground endpoints and page do with runs: start one and show stored ones. Showing a stored run never
 * calls a model.
 */
final readonly class PlaygroundRunService
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    public function __construct(private PlaygroundRunner $runner, private PlaygroundRunRepositoryInterface $runs) {}

    /**
     * @throws PlaygroundRunException when the request is refused before any target is called
     */
    public function run(PlaygroundRunRequest $request): PlaygroundRunRecord
    {
        // Up to three targets run one after another, each for at most its timeout, plus a margin for storing the run.
        RequestTimeLimit::atLeast(PlaygroundRunner::MAX_TARGETS * (int) config('ai.playground.target_timeout', 60) + 30);

        return $this->runner->run($request);
    }

    public function find(string $id): ?PlaygroundRunRecord
    {
        return $this->runs->find($id);
    }

    /**
     * @return list<PlaygroundRunSummary>
     */
    public function recent(int $limit = self::DEFAULT_LIMIT): array
    {
        return $this->runs->recent($limit);
    }
}
