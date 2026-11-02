<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class SemanticBenchReport
{
    /**
     * @param  list<float>  $embedMs  one sample per query run, milliseconds
     * @param  list<float>  $dbMs
     * @param  array<int, array{recall_at_10: float, db_ms_p50: float}>  $efSweep  keyed by ef_search
     * @param  array<string, int>  $probeBodyTypes
     */
    public function __construct(
        public string $model,
        public string $provider,
        public string $pgvector,
        public ?string $ollamaVersion,
        public ?int $ollamaNumParallel,
        public int $queries,
        public int $repeat,
        public array $embedMs,
        public array $dbMs,
        public float $recallAtTen,
        public int $efSearch,
        public array $efSweep,
        public ?float $indexBuildSeconds,
        public int $indexBytes,
        public string $probeQuery,
        public array $probeBodyTypes,
        public int $probeMatching,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $total = array_map(fn (float $embed, float $db): float => $embed + $db, $this->embedMs, $this->dbMs);

        return [
            'model' => $this->model,
            'provider' => $this->provider,
            'pgvector' => $this->pgvector,
            'ollama_version' => $this->ollamaVersion,
            'ollama_num_parallel' => $this->ollamaNumParallel,
            'queries' => $this->queries,
            'repeat' => $this->repeat,
            'samples' => count($this->embedMs),
            'embed_ms' => $this->percentiles($this->embedMs),
            'db_ms' => $this->percentiles($this->dbMs),
            'total_ms' => $this->percentiles($total),
            'recall_at_10' => round($this->recallAtTen, 4),
            'ef_search' => $this->efSearch,
            'ef_sweep' => $this->efSweep,
            'index_build_seconds' => $this->indexBuildSeconds === null ? null : round($this->indexBuildSeconds, 3),
            'index_bytes' => $this->indexBytes,
            'probe' => [
                'query' => $this->probeQuery,
                'total' => array_sum($this->probeBodyTypes),
                'matching' => $this->probeMatching,
                'body_types' => $this->probeBodyTypes,
            ],
        ];
    }

    /**
     * Nearest-rank percentiles of the samples.
     *
     * @param  list<float>  $samples
     * @return array{p50: float, p95: float, mean: float}
     */
    private function percentiles(array $samples): array
    {
        if ($samples === []) {
            return ['p50' => 0.0, 'p95' => 0.0, 'mean' => 0.0];
        }

        sort($samples);
        $rank = fn (float $p): float => round($samples[max(0, (int) ceil($p * count($samples)) - 1)], 3);

        return ['p50' => $rank(0.50), 'p95' => $rank(0.95), 'mean' => round(array_sum($samples) / count($samples), 3)];
    }
}
