<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ModerationEvalOutcome;
use App\DTOs\ModerationResult;
use App\Rules\ModerationRules;

/**
 * The maths of the moderation eval. Items whose answer was never accepted ("invalid" or "failed") count against the
 * JSON-valid rates and are wrong in every classification figure (a risky one is a false negative, a safe one a false
 * positive), so a model cannot look better by not answering.
 *
 * The headline figures (accuracy, precision, recall, F1 and the hard-negative false positives) are read at the
 * best-F1 threshold of the curve, the one a calibrated configuration would use. The same figures at the configured
 * threshold stay in "at_configured_threshold" and "false_positives_on_hard_negatives_at_configured_threshold".
 */
final readonly class ModerationEvalScorer
{
    /**
     * Thresholds of the curve, in percent.
     */
    public const CURVE_START = 10;

    public const CURVE_END = 90;

    public const CURVE_STEP = 5;

    /**
     * @param  list<ModerationEvalOutcome>  $outcomes
     * @return array<string, mixed>
     */
    public function score(string $model, array $outcomes): array
    {
        $classified = array_values(array_filter($outcomes, fn (ModerationEvalOutcome $o): bool => $o->result->status === ModerationResult::CLASSIFIED));
        $total = count($outcomes);
        $threshold = $outcomes === [] ? null : $outcomes[0]->result->threshold;

        $curve = $this->curve($outcomes);
        $best = $this->best($curve);

        return [
            'model' => $model,
            'items' => $total,
            'json_valid_first_try' => $this->rate(count(array_filter($classified, fn (ModerationEvalOutcome $o): bool => $o->result->attempts === 1)), $total),
            'json_valid_after_retry' => $this->rate(count($classified), $total),
            'invalid' => count(array_filter($outcomes, fn (ModerationEvalOutcome $o): bool => $o->result->status === ModerationResult::INVALID)),
            'failed' => count(array_filter($outcomes, fn (ModerationEvalOutcome $o): bool => $o->result->status === ModerationResult::FAILED)),
            'scored' => count($classified),
            'threshold' => $threshold,
            'at_configured_threshold' => $threshold === null ? null : $this->confusion($outcomes, fn (ModerationEvalOutcome $o): bool => (bool) $o->result->risky),
            'threshold_curve' => $curve,
            'best_f1' => $best,
            'false_positives_on_hard_negatives' => $best === null ? null : $this->hardNegatives($outcomes, fn (ModerationEvalOutcome $o): bool => (float) $o->result->risk >= $best['threshold']),
            'false_positives_on_hard_negatives_at_configured_threshold' => $threshold === null ? null : $this->hardNegatives($outcomes, fn (ModerationEvalOutcome $o): bool => (bool) $o->result->risky),
            'reason_recall' => $this->reasonRecall($classified),
            'latency_ms' => $this->latency($outcomes),
        ];
    }

    /**
     * @param  list<ModerationEvalOutcome>  $outcomes
     * @return list<array<string, mixed>>
     */
    private function curve(array $outcomes): array
    {
        $curve = [];

        for ($percent = self::CURVE_START; $percent <= self::CURVE_END; $percent += self::CURVE_STEP) {
            $threshold = $percent / 100;
            $curve[] = ['threshold' => $threshold] + $this->confusion($outcomes, fn (ModerationEvalOutcome $o): bool => (float) $o->result->risk >= $threshold);
        }

        return $curve;
    }

    /**
     * The threshold with the highest F1; the highest one when several tie. A point without an F1 is skipped, and
     * nothing is calibrated when no point has one.
     *
     * @param  list<array<string, mixed>>  $curve
     * @return array<string, mixed>|null
     */
    private function best(array $curve): ?array
    {
        $best = null;

        foreach ($curve as $point) {
            if ($point['f1'] !== null && ($best === null || $point['f1'] >= $best['f1'])) {
                $best = $point;
            }
        }

        return $best;
    }

    /**
     * @param  list<ModerationEvalOutcome>  $outcomes
     * @param  callable(ModerationEvalOutcome): bool  $flagged
     * @return array{tp: int, fp: int, fn: int, tn: int, accuracy: float|null, precision: float|null, recall: float|null, f1: float|null}
     */
    private function confusion(array $outcomes, callable $flagged): array
    {
        $tp = $fp = $fn = $tn = 0;

        foreach ($outcomes as $outcome) {
            $risky = $outcome->item->label === 'risky';
            $answered = $outcome->result->status === ModerationResult::CLASSIFIED;

            match (true) {
                $answered && $risky && $flagged($outcome) => $tp++,
                ! $answered && ! $risky, $answered && ! $risky && $flagged($outcome) => $fp++,
                $risky => $fn++,
                default => $tn++,
            };
        }

        $precision = $tp + $fp === 0 ? null : $tp / ($tp + $fp);
        $recall = $tp + $fn === 0 ? null : $tp / ($tp + $fn);
        $f1 = $precision === null || $recall === null || $precision + $recall === 0.0 ? null : 2 * $precision * $recall / ($precision + $recall);

        return [
            'tp' => $tp,
            'fp' => $fp,
            'fn' => $fn,
            'tn' => $tn,
            'accuracy' => $outcomes === [] ? null : round(($tp + $tn) / count($outcomes), 4),
            'precision' => $precision === null ? null : round($precision, 4),
            'recall' => $recall === null ? null : round($recall, 4),
            'f1' => $f1 === null ? null : round($f1, 4),
        ];
    }

    /**
     * @param  list<ModerationEvalOutcome>  $outcomes
     * @param  callable(ModerationEvalOutcome): bool  $flagged  whether an answered item counts as risky
     * @return array{count: int, of: int, ids: list<string>}
     */
    private function hardNegatives(array $outcomes, callable $flagged): array
    {
        $hard = array_values(array_filter($outcomes, fn (ModerationEvalOutcome $o): bool => ModerationEvalSet::isHardNegative($o->item)));
        $wrong = array_values(array_filter($hard, fn (ModerationEvalOutcome $o): bool => $o->result->status !== ModerationResult::CLASSIFIED || $flagged($o)));

        return ['count' => count($wrong), 'of' => count($hard), 'ids' => array_map(fn (ModerationEvalOutcome $o): string => $o->item->id, $wrong)];
    }

    /**
     * Of the reasons the labels expect on risky items, the share the model named; overall and for each reason.
     *
     * @param  list<ModerationEvalOutcome>  $classified
     * @return array{overall: array{found: int, of: int, rate: float|null}, by_reason: array<string, array{found: int, of: int, rate: float|null}>}
     */
    private function reasonRecall(array $classified): array
    {
        $found = $expected = array_fill_keys(ModerationRules::REASONS, 0);

        foreach ($classified as $outcome) {
            foreach ($outcome->item->reasons as $reason) {
                $expected[$reason]++;
                $found[$reason] += in_array($reason, $outcome->result->reasons, true) ? 1 : 0;
            }
        }

        $byReason = [];

        foreach ($expected as $reason => $of) {
            if ($of > 0) {
                $byReason[$reason] = $this->rate($found[$reason], $of);
            }
        }

        return ['overall' => $this->rate(array_sum($found), array_sum($expected)), 'by_reason' => $byReason];
    }

    /**
     * @param  list<ModerationEvalOutcome>  $outcomes
     * @return array{p50: int|null, p95: int|null, mean: float|null}
     */
    private function latency(array $outcomes): array
    {
        $samples = array_map(fn (ModerationEvalOutcome $o): int => $o->latencyMs, $outcomes);

        if ($samples === []) {
            return ['p50' => null, 'p95' => null, 'mean' => null];
        }

        return ['p50' => NlEvalScorer::percentile($samples, 50), 'p95' => NlEvalScorer::percentile($samples, 95), 'mean' => round(array_sum($samples) / count($samples), 1)];
    }

    /**
     * @return array{found: int, of: int, rate: float|null}
     */
    private function rate(int $found, int $of): array
    {
        return ['found' => $found, 'of' => $of, 'rate' => $of === 0 ? null : round($found / $of, 4)];
    }
}
