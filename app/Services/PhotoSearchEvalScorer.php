<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The metrics of the photo-search eval. A query is a list of relevance flags, best result first, already cut to the
 * top k; an empty list is a query that returned nothing.
 */
final readonly class PhotoSearchEvalScorer
{
    /**
     * Minimum scores of the threshold curve, from which min_score is calibrated.
     */
    public const THRESHOLDS = [0.15, 0.175, 0.2, 0.225, 0.25, 0.275, 0.3, 0.325, 0.35];

    /**
     * @param  list<list<bool>>  $queries
     * @return array{queries: int, answered: int, coverage: float, precision: float, mrr: float, hit_rate: float, objective: float}
     */
    public function score(array $queries): array
    {
        $count = count($queries);
        $answered = 0;
        $hits = 0;
        $precision = 0.0;
        $reciprocal = 0.0;

        foreach ($queries as $flags) {
            if ($flags === []) {
                continue;
            }

            $answered++;
            $relevant = count(array_filter($flags));
            $precision += $relevant / count($flags);
            $first = array_search(true, $flags, true);

            if ($first !== false) {
                $hits++;
                $reciprocal += 1 / ($first + 1);
            }
        }

        $meanPrecision = $this->mean($precision, $count);
        $coverage = $this->mean($answered, $count);

        return [
            'queries' => $count,
            'answered' => $answered,
            'coverage' => round($coverage, 4),
            'precision' => round($meanPrecision, 4),
            'mrr' => round($this->mean($reciprocal, $count), 4),
            'hit_rate' => round($this->mean($hits, $count), 4),
            'objective' => round($meanPrecision * $coverage, 4),
        ];
    }

    /**
     * The threshold with the highest objective; on a tie the lower threshold wins. Null when the curve is empty or
     * every objective is 0.
     *
     * @param  list<array{threshold: float, objective: float}>  $curve  lowest threshold first
     */
    public function choose(array $curve): ?float
    {
        $best = null;

        foreach ($curve as $point) {
            if ($best === null || $point['objective'] > $best['objective']) {
                $best = $point;
            }
        }

        // No relevant result at any threshold (a wrong label, an encoder returning noise) is not a calibration.
        return $best === null || $best['objective'] <= 0.0 ? null : $best['threshold'];
    }

    private function mean(float|int $sum, int $count): float
    {
        return $count === 0 ? 0.0 : $sum / $count;
    }
}
