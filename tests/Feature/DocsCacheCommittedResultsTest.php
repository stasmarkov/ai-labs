<?php

declare(strict_types=1);

/**
 * Reads a committed result file of the 2026-11-03 answer cache run.
 *
 * @return array<string, mixed>
 */
function committedCacheResult(string $label, string $file): array
{
    $path = base_path("bench/results/docs-cache/2026-11-03/{$label}/{$file}");

    expect($path)->toBeFile();

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('AC-8 ships the suggested default of the committed calibration run', function (): void {
    $calibration = committedCacheResult('run', 'calibration.json');
    $shipped = (require base_path('config/ai.php'))['docs']['cache']['min_similarity'];

    expect($calibration['metadata']['pair_set_version'])->toBe('v1')
        ->and($calibration['metadata']['pairs'])->toBe(48)
        ->and($calibration['suggested_default']['threshold'])->toBe(0.94)
        ->and($calibration['suggested_default']['lost_paraphrases'])->toBe(24)
        ->and($calibration['near_miss']['max'])->toBeLessThan($calibration['suggested_default']['threshold'])
        ->and($calibration['metadata']['configured_threshold'])->toBe($calibration['suggested_default']['threshold'])
        ->and($shipped)->toBe($calibration['suggested_default']['threshold']);
})->skip(fn (): bool => filled(env('AI_DOCS_CACHE_MIN_SIMILARITY')), 'an env override is set');

it('NFR-2 serves no near-miss of the committed pair set at the configured threshold', function (): void {
    $calibration = committedCacheResult('run', 'calibration.json');
    $threshold = $calibration['configured']['threshold'];
    $nearMisses = array_filter($calibration['pairs'], fn (array $pair): bool => $pair['label'] === 'near_miss');

    expect($calibration['configured']['fpr'])->toBe(0)
        ->and($nearMisses)->toHaveCount(24)
        ->and(array_filter($nearMisses, fn (array $pair): bool => $pair['similarity'] >= $threshold))->toBe([]);
});

it('AC-11 commits a hit/miss run of 10 eval questions with a median per path', function (): void {
    $run = committedCacheResult('hit-miss', 'hit-miss.json');
    $requests = collect($run['requests']);

    expect($run['metadata']['questions'])->toBe(10)
        ->and($requests)->toHaveCount(30)
        ->and($requests->pluck('id')->unique())->toHaveCount(10)
        ->and($requests->pluck('step')->unique()->sort()->values()->all())->toBe(['exact', 'fresh', 'paraphrase'])
        ->and($requests->every(fn (array $row): bool => $row['status'] === 200 && $row['done']))->toBeTrue()
        ->and($requests->where('step', 'fresh')->every(fn (array $row): bool => $row['path'] === 'miss'))->toBeTrue();

    $hits = $requests->where('path', '!=', 'miss')->count();

    expect($run['hit_rate'])->toBe($hits / 30)
        ->and($run['stats'])->toContain('Hits by kind');

    foreach (['fresh', 'exact', 'paraphrase'] as $step) {
        $times = $requests->where('step', $step)->pluck('ms')->sort()->values();

        expect($run['paths'][$step]['requests'])->toBe(10)
            ->and($run['paths'][$step]['median_ms_all'])->toEqual(($times[4] + $times[5]) / 2);
    }
});

it('NFR-1 records an exact hit under 100 ms and a semantic hit under 300 ms at the median of at least 10 hits each', function (): void {
    $run = committedCacheResult('latency', 'hit-miss.json');
    $requests = collect($run['requests']);

    foreach (['exact' => 100, 'semantic' => 300] as $path => $budget) {
        $hits = $requests->where('path', $path);
        $times = $hits->pluck('ms')->sort()->values();
        $middle = intdiv($times->count(), 2);
        $median = $times->count() % 2 === 1 ? $times[$middle] : ($times[$middle - 1] + $times[$middle]) / 2;
        $step = $path === 'exact' ? 'exact' : 'paraphrase';

        expect($times->count())->toBeGreaterThanOrEqual(10)
            ->and($hits->every(fn (array $row): bool => $row['step'] === $step && $row['status'] === 200 && $row['done']))->toBeTrue()
            ->and($median)->toBeLessThan($budget)
            ->and($run['paths'][$step]['median_ms_by_path'][$path])->toEqual($median);
    }

    $stored = $requests->where('step', 'fresh')->pluck('id')->intersect($requests->where('path', 'exact')->pluck('id'));

    expect($stored->count())->toBeGreaterThanOrEqual(10);
});
