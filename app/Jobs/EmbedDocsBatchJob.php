<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\InvalidEmbeddingException;
use App\Services\DocsEmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class EmbedDocsBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * Seconds before the second and third try, so a model still loading gets time.
     *
     * @var list<int>
     */
    public array $backoff = [5, 30];

    /**
     * @param  list<int>  $chunkIds
     */
    public function __construct(public readonly array $chunkIds) {}

    public function handle(DocsEmbeddingService $service): void
    {
        try {
            $service->embedBatch($this->chunkIds);
        } catch (InvalidEmbeddingException $exception) {
            // A wrong answer from the model repeats on retry, so fail at once.
            $this->fail($exception);
        }
    }
}
