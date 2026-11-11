<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PhotoEmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class EmbedPhotosBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const QUEUE = 'ai-vision';

    /**
     * An encoder failure is recorded as a failed ai_runs row by the service, so the queue never retries the batch.
     */
    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<int>  $photoIds
     */
    public function __construct(public readonly array $photoIds, public readonly ?string $runId = null)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(PhotoEmbeddingService $service): void
    {
        $service->embedBatch($this->photoIds, $this->runId);
    }
}
