<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The answer stage for one model: every repeat's rows, summarised per run and across runs.
 */
final readonly class RagModelReport
{
    /**
     * @param  list<list<RagAnswerRow>>  $runs  one list of rows per repeat
     */
    public function __construct(
        public string $model,
        public array $runs,
        public ?float $medianLatencyMs,
        public ?float $medianTtftMs,
    ) {}

    /**
     * Per-run figures: means over the scored answers (ungraded ones are left out) and the counts.
     *
     * A mean over nothing is null, not 0: a run with no graded answer has no groundedness, and no answered question has no citation rate.
     *
     * @return list<array{correctness: float|null, groundedness: float|null, citation_rate: float|null, declined_correctly: int, off_topic: int, false_dont_know: int, false_refusal: int, on_topic: int, ungraded: int, failed: int}>
     */
    public function runSummaries(): array
    {
        return array_map(fn (array $rows): array => self::summarise($rows), $this->runs);
    }

    /**
     * Median of the run means and their range, for correctness, groundedness and citation rate. Runs without a value
     * are skipped; a figure no run has is null.
     *
     * @return array<string, array{median: float, min: float, max: float}|null>
     */
    public function acrossRuns(): array
    {
        $summaries = $this->runSummaries();
        $spread = [];

        foreach (['correctness', 'groundedness', 'citation_rate'] as $key) {
            $values = array_values(array_filter(array_column($summaries, $key), fn (?float $value): bool => $value !== null));
            $spread[$key] = $values === [] ? null : ['median' => round(self::median($values), 4), 'min' => round(min($values), 4), 'max' => round(max($values), 4)];
        }

        return $spread;
    }

    /**
     * The counts summed over every run, with the number of runs.
     *
     * @return array{runs: int, declined_correctly: int, off_topic: int, false_dont_know: int, false_refusal: int, on_topic: int, ungraded: int, failed: int}
     */
    public function totals(): array
    {
        $totals = ['runs' => count($this->runs), 'declined_correctly' => 0, 'off_topic' => 0, 'false_dont_know' => 0, 'false_refusal' => 0, 'on_topic' => 0, 'ungraded' => 0, 'failed' => 0];

        foreach ($this->runSummaries() as $summary) {
            foreach (array_keys($totals) as $key) {
                if ($key !== 'runs') {
                    $totals[$key] += $summary[$key];
                }
            }
        }

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $summaries = $this->runSummaries();

        return [
            'model' => $this->model,
            'across_runs' => $this->acrossRuns(),
            'totals' => $this->totals(),
            'median_latency_ms' => $this->medianLatencyMs,
            'median_ttft_ms' => $this->medianTtftMs,
            'runs' => array_map(fn (array $rows, int $index): array => [
                'run' => $index + 1,
                'summary' => $summaries[$index],
                'answers' => array_map(fn (RagAnswerRow $row): array => $row->toArray(), $rows),
            ], $this->runs, array_keys($this->runs)),
        ];
    }

    /**
     * @param  list<RagAnswerRow>  $rows
     * @return array{correctness: float|null, groundedness: float|null, citation_rate: float|null, declined_correctly: int, off_topic: int, false_dont_know: int, false_refusal: int, on_topic: int, ungraded: int, failed: int}
     */
    private static function summarise(array $rows): array
    {
        $answered = array_values(array_filter($rows, fn (RagAnswerRow $row): bool => $row->outcome === 'completed'));
        $count = fn (string $category, ?bool $onTopic = null): int => count(array_filter(
            $rows,
            fn (RagAnswerRow $row): bool => $row->category === $category && ($onTopic === null || $row->onTopic === $onTopic),
        ));

        return [
            'correctness' => self::mean(array_filter(array_map(fn (RagAnswerRow $row): ?int => $row->correctness, $rows), fn (?int $value): bool => $value !== null)),
            'groundedness' => self::mean(array_filter(array_map(fn (RagAnswerRow $row): ?int => $row->groundedness, $rows), fn (?int $value): bool => $value !== null)),
            'citation_rate' => $answered === [] ? null : round(count(array_filter($answered, fn (RagAnswerRow $row): bool => $row->cited)) / count($answered), 4),
            'declined_correctly' => $count(RagAnswerRow::DECLINED_CORRECTLY),
            'off_topic' => count(array_filter($rows, fn (RagAnswerRow $row): bool => ! $row->onTopic)),
            'false_dont_know' => $count(RagAnswerRow::FALSE_DONT_KNOW),
            'false_refusal' => $count(RagAnswerRow::FALSE_REFUSAL),
            'on_topic' => count(array_filter($rows, fn (RagAnswerRow $row): bool => $row->onTopic)),
            'ungraded' => $count(RagAnswerRow::UNGRADED),
            'failed' => $count(RagAnswerRow::FAILED),
        ];
    }

    /**
     * @param  array<int, int|float>  $values
     */
    private static function mean(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 4);
    }

    /**
     * @param  array<int, int|float>  $values  not empty
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
