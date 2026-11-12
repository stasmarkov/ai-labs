<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\DuplicateJudgementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class JudgeDuplicateGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const QUEUE = 'ai-vision';

    /**
     * The attempts happen inside the job, one model call each, so the queue itself never retries.
     */
    public int $tries = 1;

    /** Three attempts with up to six images each, at the provider's HTTP timeout, plus room for the run rows. */
    /** Below the database queue retry_after (480 s): a longer job would be reserved twice and fail while running. */
    public int $timeout = 420;

    public function __construct(public readonly int $judgementId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(DuplicateJudgementService $service): void
    {
        $service->judge($this->judgementId);
    }

    public function failed(Throwable $exception): void
    {
        app(DuplicateJudgementService::class)->abandon($this->judgementId, $exception);
    }
}
