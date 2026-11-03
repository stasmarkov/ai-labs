<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\DocsAnswerAgent;
use App\Contracts\AiRunRepositoryInterface;
use App\DTOs\DocsCacheRunCounts;
use App\DTOs\DocsCacheStats;
use App\DTOs\DocsCacheWindowStats;
use Carbon\CarbonImmutable;

/**
 * The numbers behind `docs:cache --stats` and the pruning behind `--prune`.
 */
final readonly class DocsCacheMaintenance
{
    public function __construct(
        private DocsAnswerCache $cache,
        private AiRunRepositoryInterface $runs,
    ) {}

    public function stats(): DocsCacheStats
    {
        $agent = new DocsAnswerAgent;
        $entries = $this->cache->countEntries($agent->promptVersion(), $agent->modelName());

        return new DocsCacheStats(
            lastDay: $this->window($this->runs->docsCacheRuns(CarbonImmutable::now()->subDay())),
            allTime: $this->window($this->runs->docsCacheRuns(null)),
            currentEntries: $entries['current'],
            staleEntries: $entries['stale'],
        );
    }

    public function prune(): int
    {
        $agent = new DocsAnswerAgent;

        return $this->cache->prune($agent->promptVersion(), $agent->modelName());
    }

    private function window(DocsCacheRunCounts $runs): DocsCacheWindowStats
    {
        $hits = array_sum($runs->hitsByKind);
        $total = $hits + $runs->completed;

        return new DocsCacheWindowStats(
            hits: $hits,
            completed: $runs->completed,
            hitRate: $total === 0 ? null : $hits / $total,
            hitsByKind: $runs->hitsByKind,
            savedLatencySumMs: array_sum($runs->savedLatenciesMs),
            savedLatencyMedianMs: $this->median($runs->savedLatenciesMs),
        );
    }

    /**
     * @param  list<int>  $sorted
     */
    private function median(array $sorted): ?float
    {
        $count = count($sorted);

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? (float) $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }
}
