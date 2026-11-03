<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\RagCalibration;
use App\DTOs\RagEvalQuestion;
use App\DTOs\RagSimilarityStats;
use App\DTOs\RagThresholdPoint;
use App\Exceptions\RagEvalDataException;

/**
 * Picks the relevance threshold that best separates on-topic from off-topic questions by their top vector similarity.
 */
final readonly class ThresholdCalibrator
{
    private const EPSILON = 1e-9;

    /**
     * @param  list<RagEvalQuestion>  $questions
     * @param  array<string, float>  $topSimilarity  by question id
     *
     * @throws RagEvalDataException when either group has no question.
     */
    public function calibrate(array $questions, array $topSimilarity, float $configuredThreshold): RagCalibration
    {
        $on = [];
        $off = [];

        foreach ($questions as $question) {
            if (! array_key_exists($question->id, $topSimilarity)) {
                continue;
            }

            if ($question->isOnTopic()) {
                $on[] = $topSimilarity[$question->id];
            } else {
                $off[] = $topSimilarity[$question->id];
            }
        }

        if ($on === []) {
            throw RagEvalDataException::noQuestions('on-topic');
        }

        if ($off === []) {
            throw RagEvalDataException::noQuestions('off-topic');
        }

        return new RagCalibration(
            self::stats($on),
            self::stats($off),
            $this->best($on, $off),
            self::pointAt($configuredThreshold, $on, $off),
        );
    }

    /**
     * Highest J = TPR - FPR over the observed similarities; a tie goes to the higher threshold.
     *
     * @param  list<float>  $on
     * @param  list<float>  $off
     */
    private function best(array $on, array $off): RagThresholdPoint
    {
        $candidates = array_values(array_unique([...$on, ...$off]));
        rsort($candidates);

        $best = null;

        foreach ($candidates as $threshold) {
            $point = self::pointAt($threshold, $on, $off);

            if ($best === null || $point->youdenJ() > $best->youdenJ() + self::EPSILON) {
                $best = $point;
            }
        }

        return $best;
    }

    /**
     * @param  list<float>  $on
     * @param  list<float>  $off
     */
    private static function pointAt(float $threshold, array $on, array $off): RagThresholdPoint
    {
        return new RagThresholdPoint($threshold, self::shareAtLeast($on, $threshold), self::shareAtLeast($off, $threshold));
    }

    /**
     * @param  list<float>  $values
     */
    private static function shareAtLeast(array $values, float $threshold): float
    {
        return count(array_filter($values, fn (float $value): bool => $value >= $threshold)) / count($values);
    }

    /**
     * @param  list<float>  $values  not empty
     */
    private static function stats(array $values): RagSimilarityStats
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

        return new RagSimilarityStats($count, $values[0], $median, $values[$count - 1]);
    }
}
