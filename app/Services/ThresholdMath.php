<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\RagSimilarityStats;
use App\DTOs\RagThresholdPoint;

/**
 * The threshold math both calibrators share: summary statistics, the true and false positive rates at a threshold, and
 * the Youden-best threshold. A value counts as served when it is at or above the threshold, exactly as the cache and
 * the relevance gate compare, so a reported rate is what the lookup would do.
 */
final readonly class ThresholdMath
{
    private const EPSILON = 1e-9;

    /**
     * @param  list<float>  $positive  similarities that should be served
     * @param  list<float>  $negative  similarities that should not
     */
    public static function pointAt(float $threshold, array $positive, array $negative): RagThresholdPoint
    {
        return new RagThresholdPoint($threshold, self::shareAtLeast($positive, $threshold), self::shareAtLeast($negative, $threshold));
    }

    /**
     * Highest J = TPR - FPR over the observed similarities; a tie goes to the higher threshold. J values closer than
     * EPSILON are a tie, which only absorbs floating point noise in the rates.
     *
     * @param  list<float>  $positive
     * @param  list<float>  $negative
     */
    public static function best(array $positive, array $negative): RagThresholdPoint
    {
        $candidates = array_values(array_unique([...$positive, ...$negative]));
        rsort($candidates);

        $best = null;

        foreach ($candidates as $threshold) {
            $point = self::pointAt($threshold, $positive, $negative);

            if ($best === null || $point->youdenJ() > $best->youdenJ() + self::EPSILON) {
                $best = $point;
            }
        }

        return $best;
    }

    /**
     * @param  list<float>  $values  not empty
     */
    public static function stats(array $values): RagSimilarityStats
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

        return new RagSimilarityStats($count, $values[0], $median, $values[$count - 1]);
    }

    /**
     * @param  list<float>  $values
     */
    private static function shareAtLeast(array $values, float $threshold): float
    {
        return count(array_filter($values, fn (float $value): bool => $value >= $threshold)) / count($values);
    }
}
