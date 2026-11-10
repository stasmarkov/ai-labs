<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PhotoExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class ExtractPhotoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const QUEUE = 'ai-vision';

    /**
     * The attempts happen inside the job, one model call each, so the queue itself never retries.
     */
    public int $tries = 1;

    /** Three attempts at the provider's HTTP timeout (120 s by default) plus room for validation and the run rows. */
    public int $timeout = 420;

    public function __construct(public readonly int $extractionId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(PhotoExtractionService $service): void
    {
        $service->extract($this->extractionId);
    }

    public function failed(Throwable $exception): void
    {
        app(PhotoExtractionService::class)->abandon($this->extractionId, $exception);
    }
}
