<?php

declare(strict_types=1);

/**
 * Reads a committed result file of the 2026-11-03 run.
 *
 * @return array<string, mixed>
 */
function committedRagResult(string $label, string $file): array
{
    $path = base_path("bench/results/rag-eval/2026-11-03/{$label}/{$file}");

    expect($path)->toBeFile();

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('AC-9 commits the 500/300 run with recall for 3 k x 3 modes and three answer repeats per model', function (string $label, string $answerModel, string $grader): void {
    $recall = committedRagResult($label, 'recall.json');
    $calibration = committedRagResult($label, 'calibration.json');
    $answers = committedRagResult($label, 'answers.json');

    foreach ([$recall, $calibration, $answers] as $file) {
        expect($file['metadata']['chunk_settings']['max_tokens'])->toBe(500)
            ->and($file['metadata']['chunk_settings']['merge_below_tokens'])->toBe(300)
            ->and($file['metadata']['eval_set_version'])->toBe('v1')
            ->and($file['metadata']['grader_model'])->toBe($grader)
            ->and($file['metadata']['answer_models'])->toBe([$answerModel])
            ->and($file['metadata']['repeat'])->toBe(3);
    }

    expect($answers['models'])->toHaveCount(1)
        ->and($answers['models'][0]['model'])->toBe($answerModel)
        ->and($answers['models'][0]['runs'])->toHaveCount(3)
        ->and($calibration['on_topic']['count'])->toBe(24)
        ->and($calibration['off_topic']['count'])->toBe(6);
})->with([
    'qwen answers graded by gemma' => ['answers-qwen-500-300', 'qwen3.5:9b', 'gemma4:12b'],
    'gemma answers graded by qwen' => ['answers-gemma-500-300', 'gemma4:12b', 'qwen3.5:9b'],
]);

it('AC-9 commits recall for every k and mode at 500/300', function (): void {
    $cells = committedRagResult('answers-qwen-500-300', 'recall.json')['cells'];

    $pairs = array_map(fn (array $cell): string => $cell['mode'].':'.$cell['k'], $cells);
    sort($pairs);

    $expected = [];
    foreach (['vector', 'text', 'hybrid'] as $mode) {
        foreach ([3, 6, 10] as $k) {
            $expected[] = "{$mode}:{$k}";
        }
    }
    sort($expected);

    expect($pairs)->toBe($expected);
});

it('AC-9 commits the reduced 300/180 run with one qwen answer run', function (): void {
    $recall = committedRagResult('answers-qwen-300-180', 'recall.json');
    $answers = committedRagResult('answers-qwen-300-180', 'answers.json');

    expect($recall['metadata']['chunk_settings']['max_tokens'])->toBe(300)
        ->and($recall['metadata']['chunk_settings']['merge_below_tokens'])->toBe(180)
        ->and($recall['cells'])->toHaveCount(9)
        ->and($answers['metadata']['repeat'])->toBe(1)
        ->and($answers['models'][0]['model'])->toBe('qwen3.5:9b')
        ->and($answers['models'][0]['runs'])->toHaveCount(1)
        ->and(file_exists(base_path('bench/results/rag-eval/2026-11-03/answers-qwen-300-180/calibration.json')))->toBeTrue();
});

it('AC-9 and NFR-1 record the recall-only run that was timed', function (): void {
    $recall = committedRagResult('recall-500-300', 'recall.json');

    expect($recall['cells'])->toHaveCount(9)
        ->and(file_exists(base_path('bench/results/rag-eval/2026-11-03/recall-500-300/answers.json')))->toBeFalse();
});

it('AC-9 ships the threshold that Decision 4 picks from the 500/300 calibration', function (): void {
    $calibration = committedRagResult('answers-qwen-500-300', 'calibration.json');
    $best = $calibration['best'];
    $configured = $calibration['configured'];

    $replaces = $best['tpr'] > $configured['tpr'] && $best['fpr'] <= $configured['fpr'];
    $expected = $replaces ? $best['threshold'] : $configured['threshold'];

    $shipped = (require base_path('config/ai.php'))['docs']['answer']['min_similarity'];

    expect($shipped)->toBe((float) $expected);
})->skip(fn (): bool => filled(env('AI_DOCS_ANSWER_MIN_SIMILARITY')), 'an env override is set');

it('AC-5 commits a positive median TTFT for every model report', function (string $label): void {
    $answers = committedRagResult($label, 'answers.json');

    expect($answers['models'])->not->toBeEmpty();

    foreach ($answers['models'] as $report) {
        expect($report['median_ttft_ms'])->toBeNumeric()->toBeGreaterThan(0);
    }
})->with([
    'qwen 500/300' => ['answers-qwen-500-300'],
    'gemma 500/300' => ['answers-gemma-500-300'],
    'qwen 300/180' => ['answers-qwen-300-180'],
]);
