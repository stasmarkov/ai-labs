<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\SimilarEvalQuery;

/**
 * The metrics of the similar-photos eval. Precision is the micro average: the relevant results over all results
 * returned by every query, so a query with few results weighs less than one with eight.
 */
final readonly class SimilarEvalScorer
{
    /**
     * @param  list<SimilarEvalQuery>  $queries
     * @return array{queries: int, answered: int, coverage: float, returned: int, precision_make: float, precision_make_model: float}
     */
    public function score(array $queries, int $k): array
    {
        $answered = 0;
        $returned = 0;
        $sameMake = 0;
        $sameMakeModel = 0;

        foreach ($queries as $query) {
            $results = array_slice($query->results, 0, $k);
            $answered += $results === [] ? 0 : 1;
            $returned += count($results);

            foreach ($results as $result) {
                if ($result->make === $query->make) {
                    $sameMake++;

                    if ($result->model === $query->model) {
                        $sameMakeModel++;
                    }
                }
            }
        }

        return [
            'queries' => count($queries),
            'answered' => $answered,
            'coverage' => $this->rate($answered, count($queries)),
            'returned' => $returned,
            'precision_make' => $this->rate($sameMake, $returned),
            'precision_make_model' => $this->rate($sameMakeModel, $returned),
        ];
    }

    /**
     * @param  list<int>  $microseconds
     * @return array{samples: int, p50_ms: ?float, p95_ms: ?float, mean_ms: ?float}
     */
    public function latency(array $microseconds): array
    {
        $p50 = NlEvalScorer::percentile($microseconds, 50);
        $p95 = NlEvalScorer::percentile($microseconds, 95);

        return [
            'samples' => count($microseconds),
            'p50_ms' => $p50 === null ? null : round($p50 / 1000, 3),
            'p95_ms' => $p95 === null ? null : round($p95 / 1000, 3),
            'mean_ms' => $microseconds === [] ? null : round(array_sum($microseconds) / count($microseconds) / 1000, 3),
        ];
    }

    /**
     * Photos per second of encoder time; null when no time was recorded.
     */
    public function throughput(int $photos, int $latencyMs): ?float
    {
        return $latencyMs <= 0 || $photos <= 0 ? null : round($photos / ($latencyMs / 1000), 2);
    }

    private function rate(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part / $whole, 4);
    }
}
