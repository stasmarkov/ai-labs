<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DuplicateEvalOutcome;
use App\DTOs\DuplicateEvalPhoto;
use App\DTOs\DuplicateGroupDraft;
use App\DTOs\DuplicateMember;

/**
 * The maths of the duplicate eval. A pair is a raw edge between two photos, counted before grouping and before the cap.
 * A true pair joins two photos of one original (the original with a copy, or two copies); any other edge is false.
 */
final readonly class DuplicateEvalScorer
{
    public const MIN_RECALL = 0.9;

    /** The rule that picked the cutoff: the highest threshold with recall and a clean precision, or the best F1. */
    public const RULE_CLEAN = 'recall_and_precision';

    public const RULE_F1 = 'f1_fallback';

    /**
     * @param  list<DuplicateEvalPhoto>  $photos
     */
    public function truePairs(array $photos): int
    {
        $sizes = array_count_values(array_map(fn (DuplicateEvalPhoto $photo): int => $photo->originalId, $photos));

        return array_sum(array_map(fn (int $size): int => intdiv($size * ($size - 1), 2), $sizes));
    }

    /**
     * The pairs of an original with one of its copies, by the kind of the copy. Pairs of two copies count in the overall
     * recall only.
     *
     * @param  list<DuplicateEvalPhoto>  $photos
     * @return array<string, int>
     */
    public function pairsByKind(array $photos): array
    {
        $originals = [];

        foreach ($photos as $photo) {
            if ($photo->isOriginal()) {
                $originals[$photo->id] = true;
            }
        }

        $kinds = [];

        foreach ($photos as $photo) {
            if ($photo->variant !== null && isset($originals[$photo->originalId])) {
                $kinds[$photo->variant] = ($kinds[$photo->variant] ?? 0) + 1;
            }
        }

        ksort($kinds);

        return $kinds;
    }

    /**
     * One row per threshold: the edges at or above it, split into true and false, with recall (overall and per kind of
     * copy), precision and F1. With no edge the precision is 1.0 (nothing is wrong) and the recall 0.
     *
     * @param  list<DuplicateEvalPhoto>  $photos
     * @param  array<string, array{0: int, 1: int, 2: float}>  $edges  keyed "low:high"
     * @param  list<float>  $thresholds
     * @return list<array{threshold: float, pairs: int, true_pairs: int, false_pairs: int, precision: float, recall: float, f1: float, recall_by_kind: array<string, array{found: int, total: int, recall: float}>}>
     */
    public function curve(array $photos, array $edges, array $thresholds): array
    {
        $byId = [];

        foreach ($photos as $photo) {
            $byId[$photo->id] = $photo;
        }

        $totalTrue = $this->truePairs($photos);
        $totalByKind = $this->pairsByKind($photos);
        $rows = [];

        foreach ($thresholds as $threshold) {
            $true = 0;
            $false = 0;
            $foundByKind = array_fill_keys(array_keys($totalByKind), 0);

            foreach ($edges as [$low, $high, $similarity]) {
                if ($similarity < $threshold) {
                    continue;
                }

                $a = $byId[$low] ?? null;
                $b = $byId[$high] ?? null;

                if ($a === null || $b === null || $a->originalId !== $b->originalId) {
                    $false++;

                    continue;
                }

                $true++;
                $copy = $a->variant !== null && $b->variant === null ? $a : ($b->variant !== null && $a->variant === null ? $b : null);

                if ($copy !== null) {
                    $foundByKind[$copy->variant] = ($foundByKind[$copy->variant] ?? 0) + 1;
                }
            }

            $byKind = [];

            foreach ($totalByKind as $kind => $total) {
                $byKind[$kind] = ['found' => $foundByKind[$kind], 'total' => $total, 'recall' => round($foundByKind[$kind] / $total, 4)];
            }

            $pairs = $true + $false;
            $missed = $totalTrue - $true;

            $rows[] = [
                'threshold' => $threshold,
                'pairs' => $pairs,
                'true_pairs' => $true,
                'false_pairs' => $false,
                'precision' => $pairs === 0 ? 1.0 : round($true / $pairs, 4),
                'recall' => $totalTrue === 0 ? 0.0 : round($true / $totalTrue, 4),
                'f1' => $true === 0 ? 0.0 : round(2 * $true / (2 * $true + $false + $missed), 6),
                'recall_by_kind' => $byKind,
            ];
        }

        return $rows;
    }

    /**
     * The highest threshold whose overall recall is at least 0.9 with no false pair; when none exists, the threshold with
     * the best F1 (ties: the higher one).
     *
     * @param  list<array{threshold: float, pairs: int, true_pairs: int, false_pairs: int, precision: float, recall: float, f1: float}>  $curve
     * @return array{threshold: float, rule: string}
     */
    public function chooseCutoff(array $curve): array
    {
        $clean = array_filter($curve, fn (array $row): bool => $row['recall'] >= self::MIN_RECALL && $row['false_pairs'] === 0 && $row['pairs'] > 0);

        if ($clean !== []) {
            return ['threshold' => max(array_column($clean, 'threshold')), 'rule' => self::RULE_CLEAN];
        }

        $best = null;

        foreach ($curve as $row) {
            if ($best === null || $row['f1'] > $best['f1'] || ($row['f1'] === $best['f1'] && $row['threshold'] > $best['threshold'])) {
                $best = $row;
            }
        }

        return ['threshold' => $best['threshold'] ?? 0.0, 'rule' => self::RULE_F1];
    }

    /**
     * Sorts the groups into those the model can be scored on (exactly one original and at least one copy of it, and no
     * copy of another original) and the rest, counted by why they are left out.
     *
     * @param  list<DuplicateGroupDraft>  $groups
     * @param  list<DuplicateEvalPhoto>  $photos
     * @return array{judged: list<list<DuplicateEvalPhoto>>, skipped: array{no_original: int, several_originals: int, foreign_copies: int}}
     */
    public function classifyGroups(array $groups, array $photos): array
    {
        $byId = [];

        foreach ($photos as $photo) {
            $byId[$photo->id] = $photo;
        }

        $judged = [];
        $skipped = ['no_original' => 0, 'several_originals' => 0, 'foreign_copies' => 0];

        foreach ($groups as $group) {
            $members = array_values(array_filter(array_map(
                fn (DuplicateMember $member): ?DuplicateEvalPhoto => $byId[$member->photoId] ?? null,
                $group->members,
            )));
            $originals = array_values(array_filter($members, fn (DuplicateEvalPhoto $photo): bool => $photo->isOriginal()));

            if (count($originals) === 0) {
                $skipped['no_original']++;
            } elseif (count($originals) > 1) {
                $skipped['several_originals']++;
            } elseif (count(array_filter($members, fn (DuplicateEvalPhoto $photo): bool => ! $photo->isOriginal() && $photo->originalId !== $originals[0]->id)) > 0
                || count($members) < 2) {
                $skipped['foreign_copies']++;
            } else {
                $judged[] = $members;
            }
        }

        return ['judged' => $judged, 'skipped' => $skipped];
    }

    /**
     * The metrics of one model over the judged groups. A group with no valid answer counts as a wrong pick.
     *
     * @param  list<DuplicateEvalOutcome>  $outcomes
     * @return array<string, mixed>
     */
    public function pickAccuracy(string $model, array $outcomes): array
    {
        $total = count($outcomes);
        $correct = count(array_filter($outcomes, fn (DuplicateEvalOutcome $outcome): bool => $outcome->pickedOriginal()));
        $firstTime = count(array_filter($outcomes, fn (DuplicateEvalOutcome $outcome): bool => $outcome->isValidFirstTime()));
        $valid = count(array_filter($outcomes, fn (DuplicateEvalOutcome $outcome): bool => $outcome->isValid()));
        $latencies = array_map(fn (DuplicateEvalOutcome $outcome): int => $outcome->latencyMs, $outcomes);

        return [
            'model' => $model,
            'groups' => $total,
            'picked_original' => ['count' => $correct, 'rate' => $this->rate($correct, $total)],
            'valid_first_attempt' => ['count' => $firstTime, 'rate' => $this->rate($firstTime, $total)],
            'valid_after_retries' => ['count' => $valid, 'rate' => $this->rate($valid, $total)],
            'mean_attempts' => $total === 0 ? 0.0 : round(array_sum(array_map(fn (DuplicateEvalOutcome $outcome): int => $outcome->attempts, $outcomes)) / $total, 2),
            'latency_ms' => ['p50' => NlEvalScorer::percentile($latencies, 50), 'p95' => NlEvalScorer::percentile($latencies, 95)],
        ];
    }

    private function rate(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part / $whole, 4);
    }
}
