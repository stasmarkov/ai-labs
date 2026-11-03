<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DocsCacheCalibration;
use App\DTOs\RagThresholdPoint;
use App\Exceptions\RagEvalDataException;

/**
 * Picks cache thresholds from the similarities of paraphrase pairs (to serve) and near-miss pairs (never to serve).
 */
final readonly class DocsCacheCalibrator
{
    /**
     * @param  list<float>  $paraphrase
     * @param  list<float>  $nearMiss
     *
     * @throws RagEvalDataException when either group is empty.
     */
    public function calibrate(array $paraphrase, array $nearMiss, float $configuredThreshold): DocsCacheCalibration
    {
        if ($paraphrase === []) {
            throw RagEvalDataException::noQuestions('paraphrase pair');
        }

        if ($nearMiss === []) {
            throw RagEvalDataException::noQuestions('near-miss pair');
        }

        $highestNearMiss = max($nearMiss);
        $suggested = $this->suggestedThreshold($nearMiss);

        return new DocsCacheCalibration(
            ThresholdMath::stats($paraphrase),
            ThresholdMath::stats($nearMiss),
            ThresholdMath::best($paraphrase, $nearMiss),
            $this->zeroFalseHit($paraphrase, $highestNearMiss, $nearMiss),
            ThresholdMath::pointAt($suggested, $paraphrase, $nearMiss),
            count(array_filter($paraphrase, fn (float $value): bool => $value < $suggested)),
            ThresholdMath::pointAt($configuredThreshold, $paraphrase, $nearMiss),
        );
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $value) {
            $other = $b[$index] ?? 0.0;
            $dot += $value * $other;
            $normA += $value * $value;
            $normB += $other * $other;
        }

        return $normA > 0.0 && $normB > 0.0 ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0;
    }

    /**
     * The lowest paraphrase similarity strictly above every near-miss, or null when no paraphrase is.
     *
     * @param  list<float>  $paraphrase
     * @param  list<float>  $nearMiss
     */
    private function zeroFalseHit(array $paraphrase, float $highestNearMiss, array $nearMiss): ?RagThresholdPoint
    {
        $above = array_filter($paraphrase, fn (float $value): bool => $value > $highestNearMiss);

        return $above === [] ? null : ThresholdMath::pointAt(min($above), $paraphrase, $nearMiss);
    }

    /**
     * The highest near-miss similarity rounded up to 2 decimals, plus 0.01 when that is not strictly above it. The
     * comparison is explicit, so the result is greater than every near-miss whatever the floating point rounding does,
     * and a near-miss is never served at it.
     *
     * @param  list<float>  $nearMiss
     */
    private function suggestedThreshold(array $nearMiss): float
    {
        $highest = max($nearMiss);
        $rounded = round(ceil($highest * 100) / 100, 2);

        if ($rounded <= $highest) {
            $rounded = round($rounded + 0.01, 2);
        }

        return $rounded;
    }
}
