<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\SimilarPhotoMatch;

/**
 * The maths of the hybrid eval: counting what a query returned, the rates, the calibration of the minimum score per
 * weight, and the choice of the best weight against the image-only baseline.
 */
final readonly class HybridEvalScorer
{
    /**
     * A plateau is the run of thresholds whose objective is within this of the maximum.
     */
    public const PLATEAU = 0.001;

    /**
     * The best weight must beat the baseline's precision by at least this.
     */
    public const MIN_GAIN = 0.01;

    /**
     * @param  list<SimilarPhotoMatch>  $results
     * @return array{answered: int, returned: int, make: int, make_model: int, text_pairs: int}
     */
    public function tally(string $make, string $model, array $results, int $k): array
    {
        $results = array_slice($results, 0, $k);
        $tally = ['answered' => $results === [] ? 0 : 1, 'returned' => count($results), 'make' => 0, 'make_model' => 0, 'text_pairs' => 0];

        foreach ($results as $result) {
            $tally['text_pairs'] += $result->parts !== null && ! $result->parts->imageOnly ? 1 : 0;

            if ($result->make === $make) {
                $tally['make']++;
                $tally['make_model'] += $result->model === $model ? 1 : 0;
            }
        }

        return $tally;
    }

    /**
     * @param  array{answered: int, returned: int, make: int, make_model: int, text_pairs: int}  $total
     * @return array{queries: int, coverage: float, returned: int, precision_make: float, precision_make_model: float, text_pair_share: float, objective: float}
     */
    public function metrics(array $total, int $queries): array
    {
        $coverage = $this->rate($total['answered'], $queries);
        $precision = $this->rate($total['make_model'], $total['returned']);

        return [
            'queries' => $queries,
            'coverage' => $coverage,
            'returned' => $total['returned'],
            'precision_make' => $this->rate($total['make'], $total['returned']),
            'precision_make_model' => $precision,
            'text_pair_share' => $this->rate($total['text_pairs'], $total['returned']),
            'objective' => round($precision * $coverage, 4),
        ];
    }

    /**
     * The index of the threshold to ship: the middle (the lower middle when even) of the plateau around the best
     * objective, the plateau being the consecutive thresholds within PLATEAU of it.
     *
     * @param  list<float>  $objectives
     */
    public function calibrate(array $objectives): int
    {
        $best = 0;

        foreach ($objectives as $index => $objective) {
            if ($objective > $objectives[$best]) {
                $best = $index;
            }
        }

        $floor = $objectives[$best] - self::PLATEAU;
        $low = $best;
        $high = $best;

        while ($low > 0 && $objectives[$low - 1] >= $floor) {
            $low--;
        }

        while ($high < count($objectives) - 1 && $objectives[$high + 1] >= $floor) {
            $high++;
        }

        return $low + intdiv($high - $low, 2);
    }

    /**
     * The best weight of a caption sweep: highest precision for make and model with coverage at least the baseline's,
     * the higher weight on a tie, and only when it beats the baseline by MIN_GAIN. Otherwise null: image only stays.
     *
     * @template TRow of array{w: float, precision_make_model: float, coverage: float}
     *
     * @param  list<TRow>  $sweep
     * @param  array{precision_make_model: float, coverage: float}  $baseline
     * @return TRow|null
     */
    public function best(array $sweep, array $baseline): ?array
    {
        $best = null;

        foreach ($sweep as $row) {
            if ($row['w'] >= 1.0 || $row['coverage'] < $baseline['coverage']) {
                continue;
            }

            if ($best === null || $row['precision_make_model'] > $best['precision_make_model'] || ($row['precision_make_model'] === $best['precision_make_model'] && $row['w'] > $best['w'])) {
                $best = $row;
            }
        }

        return $best !== null && self::MIN_GAIN - 1e-9 <= $best['precision_make_model'] - $baseline['precision_make_model'] ? $best : null;
    }

    /**
     * The cosine of two vectors.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $value) {
            $dot += $value * $b[$index];
            $normA += $value * $value;
            $normB += $b[$index] * $b[$index];
        }

        return $normA > 0.0 && $normB > 0.0 ? $dot / sqrt($normA * $normB) : 0.0;
    }

    private function rate(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part / $whole, 4);
    }
}
