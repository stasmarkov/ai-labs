<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\RagRunTiming;

interface RagEvalRepositoryInterface
{
    /**
     * All chunks, and those that have a vector for this model.
     *
     * @return array{total: int, embedded: int}
     */
    public function chunkCounts(string $model): array;

    /**
     * The distinct chunk sizes of the indexed documents; documents without recorded sizes count as 500 / 300.
     *
     * @return list<array{max_tokens: int, merge_below_tokens: int}>
     */
    public function chunkSettings(): array;

    /**
     * Latency and time to first token of the logged runs with these invocation ids.
     *
     * @param  list<string>  $invocationIds
     * @return list<RagRunTiming>
     */
    public function timings(array $invocationIds): array;
}
