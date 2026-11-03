<?php

declare(strict_types=1);

use App\DTOs\RagEvalQuestion;
use App\Exceptions\RagEvalDataException;
use App\Services\ThresholdCalibrator;

function calibrationQuestion(string $id, string $type): RagEvalQuestion
{
    return new RagEvalQuestion($id, $type, "question {$id}", 'answer', [], [], 1);
}

/**
 * @param  array<string, float>  $on
 * @param  array<string, float>  $off
 * @return array{0: list<RagEvalQuestion>, 1: array<string, float>}
 */
function calibrationSet(array $on, array $off): array
{
    $questions = [];

    foreach (array_keys($on) as $id) {
        $questions[] = calibrationQuestion($id, RagEvalQuestion::ON_TOPIC);
    }

    foreach (array_keys($off) as $id) {
        $questions[] = calibrationQuestion($id, RagEvalQuestion::OFF_TOPIC);
    }

    return [$questions, [...$on, ...$off]];
}

it('AC-4 reports min, median and max per group', function (): void {
    [$questions, $sims] = calibrationSet(['a' => 0.8, 'b' => 0.6, 'c' => 0.7, 'd' => 0.5], ['x' => 0.2, 'y' => 0.4, 'z' => 0.3]);

    $calibration = (new ThresholdCalibrator)->calibrate($questions, $sims, 0.5);

    expect($calibration->onTopic->toArray())->toBe(['count' => 4, 'min' => 0.5, 'median' => 0.65, 'max' => 0.8])
        ->and($calibration->offTopic->toArray())->toBe(['count' => 3, 'min' => 0.2, 'median' => 0.3, 'max' => 0.4]);
});

it('AC-4 picks the threshold with the highest Youden J', function (): void {
    [$questions, $sims] = calibrationSet(['a' => 0.9, 'b' => 0.7, 'c' => 0.45, 'd' => 0.35], ['x' => 0.5, 'y' => 0.3, 'z' => 0.2, 'w' => 0.1]);

    $best = (new ThresholdCalibrator)->calibrate($questions, $sims, 0.5)->best;

    // t=0.35: TPR 4/4, FPR 1/4 -> 0.75; t=0.45: TPR 3/4, FPR 1/4 -> 0.5; t=0.7: TPR 2/4, FPR 0 -> 0.5
    expect($best->threshold)->toBe(0.35)
        ->and($best->tpr)->toBe(1.0)
        ->and($best->fpr)->toBe(0.25)
        ->and($best->youdenJ())->toBe(0.75);
});

it('AC-4 breaks a Youden J tie with the higher threshold', function (): void {
    [$questions, $sims] = calibrationSet(['a' => 0.9, 'b' => 0.6], ['x' => 0.3, 'y' => 0.2]);

    $best = (new ThresholdCalibrator)->calibrate($questions, $sims, 0.5)->best;

    // t=0.9: TPR 1/2, FPR 0 -> 0.5; t=0.6: TPR 2/2, FPR 0 -> 1.0 beats it, then lower ones tie at 0.6 only
    expect($best->threshold)->toBe(0.6)->and($best->youdenJ())->toBe(1.0);

    [$questions, $sims] = calibrationSet(['a' => 0.9, 'b' => 0.4], ['x' => 0.4, 'y' => 0.1]);

    // t=0.9: TPR 1/2, FPR 0 -> 0.5; t=0.4: TPR 1, FPR 1/2 -> 0.5; tie goes to 0.9
    expect((new ThresholdCalibrator)->calibrate($questions, $sims, 0.5)->best->threshold)->toBe(0.9);
});

it('AC-4 reports TPR and FPR at the configured threshold with a greater-or-equal comparison', function (): void {
    [$questions, $sims] = calibrationSet(['a' => 0.9, 'b' => 0.5, 'c' => 0.3], ['x' => 0.5, 'y' => 0.2]);

    $configured = (new ThresholdCalibrator)->calibrate($questions, $sims, 0.5)->configured;

    expect($configured->threshold)->toBe(0.5)
        ->and($configured->tpr)->toEqualWithDelta(2 / 3, 1e-9)
        ->and($configured->fpr)->toBe(0.5);
});

it('AC-4 ignores questions without a similarity and rejects an empty group', function (): void {
    [$questions, $sims] = calibrationSet(['a' => 0.9], ['x' => 0.2]);
    $questions[] = calibrationQuestion('ghost', RagEvalQuestion::ON_TOPIC);

    expect((new ThresholdCalibrator)->calibrate($questions, $sims, 0.5)->onTopic->count)->toBe(1);

    [$questions, $sims] = calibrationSet(['a' => 0.9], []);
    (new ThresholdCalibrator)->calibrate($questions, $sims, 0.5);
})->throws(RagEvalDataException::class, 'off-topic');
