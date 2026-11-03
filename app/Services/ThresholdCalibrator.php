<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\RagCalibration;
use App\DTOs\RagEvalQuestion;
use App\Exceptions\RagEvalDataException;

/**
 * Picks the relevance threshold that best separates on-topic from off-topic questions by their top vector similarity.
 */
final readonly class ThresholdCalibrator
{
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
            ThresholdMath::stats($on),
            ThresholdMath::stats($off),
            ThresholdMath::best($on, $off),
            ThresholdMath::pointAt($configuredThreshold, $on, $off),
        );
    }
}
