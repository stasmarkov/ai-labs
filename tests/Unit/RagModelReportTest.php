<?php

declare(strict_types=1);

use App\DTOs\RagAnswerRow;
use App\DTOs\RagModelReport;

function reportRow(string $category, bool $onTopic, string $outcome, ?int $correctness, ?int $groundedness = null, bool $cited = false): RagAnswerRow
{
    return new RagAnswerRow('q', $onTopic, $outcome, $category, $correctness, $groundedness, $cited, 'inv', null, null, null);
}

it('AC-5 reports a mean over no values as null, not 0', function (): void {
    $report = new RagModelReport('m', [[reportRow(RagAnswerRow::FAILED, true, 'failed', null)]], null, null);

    expect($report->runSummaries()[0])->toMatchArray(['correctness' => null, 'groundedness' => null, 'citation_rate' => null, 'failed' => 1])
        ->and($report->acrossRuns())->toBe(['correctness' => null, 'groundedness' => null, 'citation_rate' => null])
        ->and($report->toArray()['across_runs']['groundedness'])->toBeNull();
});

it('AC-5 skips runs without a value when it takes the median across runs', function (): void {
    $graded = [reportRow(RagAnswerRow::GRADED, true, 'completed', 2, 2, true)];
    $failed = [reportRow(RagAnswerRow::FAILED, true, 'failed', null)];

    $across = (new RagModelReport('m', [$graded, $failed, $graded], null, null))->acrossRuns();

    expect($across['groundedness'])->toBe(['median' => 2.0, 'min' => 2.0, 'max' => 2.0])
        ->and($across['citation_rate'])->toBe(['median' => 1.0, 'min' => 1.0, 'max' => 1.0]);
});

it('AC-5 sums the counts over the runs', function (): void {
    $run = [
        reportRow(RagAnswerRow::DECLINED_CORRECTLY, false, 'no_context', 2),
        reportRow(RagAnswerRow::FALSE_DONT_KNOW, true, 'no_context', 0),
    ];
    $second = [
        reportRow(RagAnswerRow::FALSE_REFUSAL, true, 'refused', 0),
        reportRow(RagAnswerRow::DECLINED_CORRECTLY, false, 'refused', 2),
    ];

    expect((new RagModelReport('m', [$run, $second, $run], null, null))->totals())->toBe([
        'runs' => 3, 'declined_correctly' => 3, 'off_topic' => 3, 'false_dont_know' => 2, 'false_refusal' => 1, 'on_topic' => 3, 'ungraded' => 0, 'failed' => 0,
    ]);
});
