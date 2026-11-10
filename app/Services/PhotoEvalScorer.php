<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PhotoEvalOutcome;

/**
 * The metrics of one model over the sample.
 */
final readonly class PhotoEvalScorer
{
    /**
     * @param  list<PhotoEvalOutcome>  $outcomes
     * @return array<string, mixed>
     */
    public function score(string $model, array $outcomes): array
    {
        $total = count($outcomes);
        $firstTime = count(array_filter($outcomes, fn (PhotoEvalOutcome $outcome): bool => $outcome->isValidFirstTime()));
        $valid = count(array_filter($outcomes, fn (PhotoEvalOutcome $outcome): bool => $outcome->isValid()));
        $attempts = array_sum(array_map(fn (PhotoEvalOutcome $outcome): int => $outcome->attempts, $outcomes));
        $attemptLatencies = array_merge(...array_map(fn (PhotoEvalOutcome $outcome): array => $outcome->attemptLatenciesMs, $outcomes));
        $mapped = array_values(array_filter($outcomes, fn (PhotoEvalOutcome $outcome): bool => $outcome->expectedBodyType !== null));
        $agreed = count(array_filter($mapped, fn (PhotoEvalOutcome $outcome): bool => $this->agrees($outcome)));
        $coloured = count(array_filter($outcomes, fn (PhotoEvalOutcome $outcome): bool => $outcome->isValid() && $this->colours($outcome) > 0));
        $latencies = array_map(fn (PhotoEvalOutcome $outcome): int => $outcome->latencyMs, $outcomes);

        return [
            'model' => $model,
            'photos' => $total,
            'valid_first_attempt' => ['count' => $firstTime, 'rate' => $this->rate($firstTime, $total)],
            'valid_after_retries' => ['count' => $valid, 'rate' => $this->rate($valid, $total)],
            'mean_attempts' => $total === 0 ? 0.0 : round($attempts / $total, 2),
            'latency_ms' => [
                'p50' => NlEvalScorer::percentile($latencies, 50),
                'p95' => NlEvalScorer::percentile($latencies, 95),
                'mean_per_attempt' => $attemptLatencies === [] ? null : (int) round(array_sum($attemptLatencies) / count($attemptLatencies)),
            ],
            'body_type_agreement' => ['agreed' => $agreed, 'compared' => count($mapped), 'rate' => $this->rate($agreed, count($mapped))],
            'colour_presence' => ['count' => $coloured, 'rate' => $this->rate($coloured, $total)],
        ];
    }

    private function agrees(PhotoEvalOutcome $outcome): bool
    {
        return $outcome->isValid()
            && mb_strtolower((string) ($outcome->data['body_type'] ?? '')) === mb_strtolower((string) $outcome->expectedBodyType);
    }

    private function colours(PhotoEvalOutcome $outcome): int
    {
        return count((array) ($outcome->data['dominant_colours'] ?? []));
    }

    private function rate(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part / $whole, 4);
    }
}
