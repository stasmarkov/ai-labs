<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ResearchRunRepositoryInterface;
use App\Services\ResearchRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Executes a stored research run that is still `running`, so the page does not hold an HTTP request for the minute a
 * run can take.
 */
final class ExecuteResearchRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The model calls retry inside the runner, so the queue itself never retries.
     */
    public int $tries = 1;

    /**
     * The run's own time budget plus a minute for the work around the model calls, so the runner stops cleanly before the
     * queue kills the job.
     */
    public int $timeout;

    public function __construct(public readonly string $runId)
    {
        $this->timeout = (int) config('ai.research.max_seconds') + 60;
    }

    public function handle(ResearchRunner $runner): void
    {
        $runner->execute($this->runId);
    }

    /**
     * A crashed or timed-out job never leaves its run `running`; the steps and counts stored so far stay.
     */
    public function failed(Throwable $exception): void
    {
        app(ResearchRunRepositoryInterface::class)->fail($this->runId, 'job_failed');
    }
}
