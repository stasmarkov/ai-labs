<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\OllamaServerInfo;
use App\Contracts\EmbeddingStatsRepositoryInterface;
use App\Contracts\SemanticBenchRepositoryInterface;
use App\Contracts\SemanticSearchRepositoryInterface;
use App\DTOs\SemanticBenchReport;

final readonly class SemanticBenchService
{
    public const TOP = 10;

    private const BOOT_BODY_TYPES = ['estate', 'SUV', 'MPV'];

    public function __construct(
        private SemanticBenchRepositoryInterface $bench,
        private SemanticSearchRepositoryInterface $matches,
        private EmbeddingStatsRepositoryInterface $stats,
        private SemanticSearchService $search,
        private ListingEmbeddingService $embeddings,
        private OllamaServerInfo $ollama,
    ) {}

    /**
     * ef_search values compared for recall and database time.
     */
    private const EF_SWEEP = [40, 100, 200];

    public function embeddedCount(): int
    {
        return $this->bench->embeddedCount();
    }

    /**
     * Time every query (embedding and database apart), compare HNSW with an exact scan, and count the body types of the probe query.
     *
     * @param  list<string>  $queries
     */
    public function run(array $queries, int $repeat, bool $rebuildIndex, string $probe): SemanticBenchReport
    {
        $indexSeconds = $rebuildIndex ? $this->bench->rebuildIndex() : null;

        $this->search->embedQuery($queries[0]);

        $efSearch = (int) config('ai.embeddings.ef_search');
        $embedMs = [];
        $dbMs = [];
        $recall = [];
        /** @var array<int, array{recall: list<float>, db_ms: list<float>}> $sweep */
        $sweep = array_fill_keys(self::EF_SWEEP, ['recall' => [], 'db_ms' => []]);

        for ($round = 0; $round < $repeat; $round++) {
            foreach ($queries as $query) {
                $started = hrtime(true);
                $vector = $this->search->embedQuery($query);
                $embedMs[] = (hrtime(true) - $started) / 1e6;

                $started = hrtime(true);
                $this->matches->nearest($vector, self::TOP, $efSearch);
                $dbMs[] = (hrtime(true) - $started) / 1e6;

                if ($round === 0) {
                    $exact = $this->bench->exactIds($vector, self::TOP);

                    foreach (self::EF_SWEEP as $ef) {
                        $started = hrtime(true);
                        $approximate = $this->bench->approximateIds($vector, self::TOP, $ef);
                        $sweep[$ef]['db_ms'][] = (hrtime(true) - $started) / 1e6;
                        $sweep[$ef]['recall'][] = $exact === [] ? 1.0 : count(array_intersect($approximate, $exact)) / count($exact);
                    }

                    $found = array_intersect($this->bench->approximateIds($vector, self::TOP, $efSearch), $exact);
                    $recall[] = $exact === [] ? 1.0 : count($found) / count($exact);
                }
            }
        }

        $bodyTypes = [];
        $matching = 0;

        foreach ($this->search->search($probe) as $match) {
            $bodyTypes[$match->bodyType] = ($bodyTypes[$match->bodyType] ?? 0) + 1;
            $matching += in_array($match->bodyType, self::BOOT_BODY_TYPES, true) ? 1 : 0;
        }

        return new SemanticBenchReport(
            model: $this->embeddings->model(),
            provider: $this->embeddings->provider(),
            pgvector: $this->bench->pgvectorVersion(),
            ollamaVersion: $this->ollama->version($this->embeddings->provider()),
            ollamaNumParallel: $this->ollama->numParallel($this->embeddings->provider()),
            queries: count($queries),
            repeat: $repeat,
            embedMs: $embedMs,
            dbMs: $dbMs,
            recallAtTen: array_sum($recall) / count($recall),
            efSearch: $efSearch,
            efSweep: array_map(fn (array $runs): array => [
                'recall_at_10' => round(array_sum($runs['recall']) / max(1, count($runs['recall'])), 4),
                'db_ms_p50' => $this->median($runs['db_ms']),
            ], $sweep),
            indexBuildSeconds: $indexSeconds,
            indexBytes: $this->stats->documentSizes()->indexBytes,
            probeQuery: $probe,
            probeBodyTypes: $bodyTypes,
            probeMatching: $matching,
        );
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return round(count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2, 3);
    }
}
