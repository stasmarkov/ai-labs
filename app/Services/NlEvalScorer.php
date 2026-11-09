<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\NlEvalOutcome;
use App\DTOs\NlEvalQuery;

/**
 * The metrics of one model over the eval set. A call that is refused, failed or answered 422 is a miss on every field.
 */
final readonly class NlEvalScorer
{
    /**
     * @param  list<NlEvalQuery>  $queries
     * @param  list<NlEvalOutcome>  $outcomes  one per query, in the same order
     * @param  list<int>  $latenciesMs  of the calls that reached the model's answer
     * @return array<string, mixed>
     */
    public function score(string $model, array $queries, array $outcomes, array $latenciesMs): array
    {
        $total = count($queries);
        $exact = 0;
        $fieldHits = array_fill_keys(NlSearchService::FILTER_FIELDS, 0);
        $expectedTerms = 0;
        $caughtTerms = 0;
        $counts = [NlEvalOutcome::INVALID => 0, NlEvalOutcome::REFUSED => 0, NlEvalOutcome::FAILED => 0];

        foreach ($queries as $index => $query) {
            $outcome = $outcomes[$index];

            if (isset($counts[$outcome->status])) {
                $counts[$outcome->status]++;
            }

            if ($outcome->status === NlEvalOutcome::COMPLETED) {
                $matches = $this->fieldMatches($query, $outcome);
                $exact += (int) ! in_array(false, $matches, true);

                foreach ($matches as $field => $hit) {
                    $fieldHits[$field] += (int) $hit;
                }
            }

            foreach ($query->unsupported as $term) {
                $expectedTerms++;
                $caughtTerms += (int) $this->caught($term, $outcome->unsupported);
            }
        }

        return [
            'model' => $model,
            'queries' => $total,
            'exact_match' => ['count' => $exact, 'rate' => $this->rate($exact, $total)],
            'field_accuracy' => array_map(fn (int $hits): float => $this->rate($hits, $total), $fieldHits),
            'unsupported_recall' => ['expected' => $expectedTerms, 'caught' => $caughtTerms, 'recall' => $this->rate($caughtTerms, $expectedTerms)],
            'validation_422' => ['count' => $counts[NlEvalOutcome::INVALID], 'rate' => $this->rate($counts[NlEvalOutcome::INVALID], $total)],
            'refused' => $counts[NlEvalOutcome::REFUSED],
            'failed' => $counts[NlEvalOutcome::FAILED],
            'latency_ms' => [
                'count' => count($latenciesMs),
                'median' => self::percentile($latenciesMs, 50),
                'p95' => self::percentile($latenciesMs, 95),
            ],
        ];
    }

    /**
     * Whether each field of the model's output equals the expected one: strings trimmed and lowercased, integers as integers.
     *
     * @return array<string, bool>
     */
    public function fieldMatches(NlEvalQuery $query, NlEvalOutcome $outcome): array
    {
        $matches = [];

        foreach (NlSearchService::FILTER_FIELDS as $field) {
            $matches[$field] = $outcome->status === NlEvalOutcome::COMPLETED
                && $this->normalize($query->expected[$field]) === $this->normalize($outcome->extracted[$field] ?? null);
        }

        return $matches;
    }

    /**
     * The nearest-rank percentile: the smallest value with at least $percent of the values at or below it.
     *
     * @param  list<int>  $values
     */
    public static function percentile(array $values, int $percent): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        return $values[max(0, (int) ceil($percent / 100 * count($values)) - 1)];
    }

    private function normalize(mixed $value): string|int|null
    {
        return is_string($value) ? mb_strtolower(trim($value)) : $value;
    }

    /**
     * @param  list<string>  $returned
     */
    private function caught(string $term, array $returned): bool
    {
        foreach ($returned as $candidate) {
            if (str_contains(mb_strtolower($candidate), mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    private function rate(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part / $whole, 4);
    }
}
