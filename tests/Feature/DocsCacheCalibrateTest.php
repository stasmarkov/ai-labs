<?php

declare(strict_types=1);

use App\Exceptions\RagEvalDataException;
use App\Services\DocsCacheCalibrator;
use App\Services\DocsCachePairSet;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;
use Laravel\Ai\Embeddings;

function calibRoot(): string
{
    return storage_path('framework/testing/docs-cache-calibrate');
}

/**
 * @return list<float>
 */
function calibVector(float $angle): array
{
    return [cos($angle), sin($angle), ...array_fill(0, 1022, 0.0)];
}

/**
 * Writes a pair file whose pairs have these cosine similarities (text "a" at angle 0, text "b" at acos(similarity)) and fakes the embeddings.
 *
 * @param  list<float>  $paraphrase
 * @param  list<float>  $nearMiss
 * @param  list<string>  $extraLines
 */
function calibFixture(array $paraphrase, array $nearMiss, array $extraLines = [], ?int &$embedCalls = null): string
{
    $angles = [];
    $lines = [];

    foreach (['paraphrase' => $paraphrase, 'near_miss' => $nearMiss] as $label => $similarities) {
        foreach ($similarities as $index => $similarity) {
            $id = sprintf('%s-%02d', $label === 'paraphrase' ? 'para' : 'miss', $index + 1);
            $angles["{$id} a"] = 0.0;
            $angles["{$id} b"] = acos($similarity);
            $lines[] = json_encode(['id' => $id, 'label' => $label, 'a' => "{$id} a", 'b' => "{$id} b"]);
        }
    }

    Embeddings::fake(function ($prompt) use ($angles, &$embedCalls): array {
        return array_map(function (string $input) use ($angles, &$embedCalls): array {
            $embedCalls++;

            return calibVector($angles[$input] ?? 1.0);
        }, $prompt->inputs);
    });

    $file = calibRoot().'/pairs.jsonl';
    File::ensureDirectoryExists(dirname($file));
    File::put($file, implode("\n", [...$lines, ...$extraLines])."\n");

    return $file;
}

/**
 * @return list<float>
 */
function calibRange(float $from, float $to, int $count = 20): array
{
    return array_map(fn (int $i): float => $from + ($to - $from) * $i / ($count - 1), range(0, $count - 1));
}

/**
 * @param  array<string, mixed>  $options
 */
function calibRun(mixed $test, string $label, string $pairs, array $options = []): PendingCommand
{
    return $test->artisan('docs:cache-calibrate', [
        '--pairs' => $pairs,
        '--output-dir' => calibRoot()."/out/{$label}",
        '--label' => $label,
        ...$options,
    ]);
}

/**
 * @return array<string, mixed>
 */
function calibJson(string $label): array
{
    return json_decode((string) File::get(calibRoot()."/out/{$label}/calibration.json"), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    File::ensureDirectoryExists(calibRoot().'/out');
    config(['ai.docs.eval_results_root' => calibRoot().'/out', 'ai.docs.cache.min_similarity' => 0.85]);
    Http::preventStrayRequests();
    Process::fake([
        'git rev-parse HEAD' => Process::result("3333333333333333333333333333333333333333\n"),
        'git status --porcelain' => Process::result(''),
    ]);
});

afterEach(function (): void {
    File::deleteDirectory(calibRoot());
});

it('AC-7 reports per-label stats, the Youden and zero-false-hit thresholds and writes calibration.json when the ranges are separated', function (): void {
    $file = calibFixture(calibRange(0.86, 0.95), calibRange(0.50, 0.80));

    calibRun($this, 'separated', $file)
        ->expectsOutputToContain('paraphrase')
        ->expectsOutputToContain('zero false hit')
        ->assertSuccessful();

    $result = calibJson('separated');

    expect($result['paraphrase']['count'])->toBe(20)
        ->and($result['paraphrase']['min'])->toEqualWithDelta(0.86, 1e-5)
        ->and($result['paraphrase']['max'])->toEqualWithDelta(0.95, 1e-5)
        ->and($result['near_miss']['max'])->toEqualWithDelta(0.80, 1e-5)
        ->and($result['best']['threshold'])->toEqualWithDelta(0.86, 1e-5)
        ->and($result['best']['j'])->toEqual(1.0)
        ->and($result['zero_false_hit']['threshold'])->toEqualWithDelta(0.86, 1e-5)
        ->and($result['zero_false_hit']['fpr'])->toEqual(0.0)
        ->and($result['metadata']['pair_set_version'])->toBe('v1')
        ->and($result['metadata']['git_commit'])->toBe('3333333333333333333333333333333333333333')
        ->and($result['pairs'])->toHaveCount(40);
});

it('NFR-2 serves no near-miss at the configured threshold when the ranges are separated', function (): void {
    $file = calibFixture(calibRange(0.86, 0.95), calibRange(0.50, 0.80));

    calibRun($this, 'configured', $file)->assertSuccessful();

    $result = calibJson('configured');

    expect($result['configured']['threshold'])->toBe(0.85)
        ->and($result['configured']['tpr'])->toEqual(1.0)
        ->and($result['configured']['fpr'])->toEqual(0.0);
});

it('AC-7 reports none for the zero-false-hit threshold when the ranges overlap', function (): void {
    $file = calibFixture(calibRange(0.70, 0.90), calibRange(0.60, 0.92));

    calibRun($this, 'overlap', $file)
        ->expectsOutputToContain('none')
        ->assertSuccessful();

    $result = calibJson('overlap');

    expect($result['zero_false_hit'])->toBeNull()
        ->and($result['configured']['fpr'])->toBeGreaterThan(0.0)
        ->and($result['suggested_default']['fpr'])->toEqual(0.0);
});

it('AC-7 embeds every distinct text once', function (): void {
    $calls = 0;
    $file = calibFixture(calibRange(0.86, 0.95), calibRange(0.50, 0.80), embedCalls: $calls);

    calibRun($this, 'once', $file)->assertSuccessful();

    expect($calls)->toBe(80);
});

it('AC-7 refuses a bad pair file with the line number and writes nothing', function (string $line, string $message): void {
    $file = calibFixture(calibRange(0.86, 0.95), calibRange(0.50, 0.80), [$line]);

    calibRun($this, 'invalid', $file)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(File::exists(calibRoot().'/out/invalid'))->toBeFalse();
})->with([
    'invalid json' => ['{not json', 'line 41: invalid JSON'],
    'unknown label' => ['{"id":"extra-01","label":"other","a":"x","b":"y"}', 'line 41: label must be paraphrase or near_miss'],
    'duplicate id' => ['{"id":"para-01","label":"paraphrase","a":"x","b":"y"}', 'line 41: duplicate id "para-01" (first on line 1)'],
    'missing text' => ['{"id":"extra-01","label":"paraphrase","a":"x"}', 'line 41: "b" must be a non-empty string'],
    'identical texts' => ['{"id":"extra-01","label":"paraphrase","a":"Same","b":" same "}', 'line 41: "a" and "b" must differ'],
]);

it('AC-7 refuses a pair set with fewer than 20 pairs of a label', function (): void {
    $file = calibFixture(calibRange(0.86, 0.95, 19), calibRange(0.50, 0.80));

    calibRun($this, 'few', $file)
        ->expectsOutputToContain('at least 20 paraphrase pairs, found 19')
        ->assertFailed();

    expect(File::exists(calibRoot().'/out/few'))->toBeFalse();
});

it('AC-7 fails on a missing pair file', function (): void {
    calibRun($this, 'missing', calibRoot().'/nope.jsonl')
        ->expectsOutputToContain('Cache pair set not found')
        ->assertFailed();
});

it('AC-7 fails without a result when the embedding model is unavailable', function (): void {
    $file = calibFixture(calibRange(0.86, 0.95), calibRange(0.50, 0.80));
    Embeddings::fake(fn () => throw new RuntimeException('connection refused'));

    calibRun($this, 'down', $file)->assertFailed();

    expect(File::exists(calibRoot().'/out/down'))->toBeFalse();
});

it('AC-7 keeps an existing result unless forced, and replaces it atomically when forced', function (): void {
    $file = calibFixture(calibRange(0.86, 0.95), calibRange(0.50, 0.80));

    calibRun($this, 'again', $file)->assertSuccessful();
    $first = File::get(calibRoot().'/out/again/calibration.json');

    calibFixture(calibRange(0.70, 0.90), calibRange(0.60, 0.92));
    calibRun($this, 'again', $file)->expectsOutputToContain('Label exists')->assertFailed();

    expect(File::get(calibRoot().'/out/again/calibration.json'))->toBe($first);

    calibRun($this, 'again', $file, ['--force' => true])->assertSuccessful();

    expect(calibJson('again')['zero_false_hit'])->toBeNull()
        ->and(glob(calibRoot().'/out/.again.*'))->toBe([]);
});

it('AC-7 breaks a Youden tie towards the higher threshold', function (): void {
    $calibration = (new DocsCacheCalibrator)->calibrate(
        [...array_fill(0, 10, 0.9), ...array_fill(0, 10, 0.7)],
        [...array_fill(0, 10, 0.8), ...array_fill(0, 10, 0.5)],
        0.85,
    )->toArray();

    expect($calibration['best']['threshold'])->toBe(0.9)
        ->and($calibration['best']['j'])->toBe(0.5)
        ->and($calibration['zero_false_hit']['threshold'])->toBe(0.9);
});

it('AC-7 treats a paraphrase equal to the highest near-miss as not above it', function (): void {
    $calibration = (new DocsCacheCalibrator)->calibrate([0.8, 0.8, 0.9], [0.8, 0.5], 0.85)->toArray();

    expect($calibration['zero_false_hit']['threshold'])->toBe(0.9)
        ->and((new DocsCacheCalibrator)->calibrate([0.8, 0.7], [0.8, 0.5], 0.85)->toArray()['zero_false_hit'])->toBeNull();
});

it('AC-8 suggests the highest near-miss rounded up to 2 decimals, plus 0.01 when it lands on a near-miss, and counts lost paraphrases', function (): void {
    $rounded = (new DocsCacheCalibrator)->calibrate([0.80, 0.83, 0.90], [0.811, 0.5], 0.85)->toArray();
    $onValue = (new DocsCacheCalibrator)->calibrate([0.80, 0.83, 0.90], [0.82, 0.5], 0.85)->toArray();

    expect($rounded['suggested_default']['threshold'])->toBe(0.82)
        ->and($rounded['suggested_default']['lost_paraphrases'])->toBe(1)
        ->and($onValue['suggested_default']['threshold'])->toBe(0.83)
        ->and($onValue['suggested_default']['lost_paraphrases'])->toBe(1)
        ->and($onValue['suggested_default']['fpr'])->toEqual(0.0);
});

it('AC-7 needs at least one pair of each label to calibrate', function (): void {
    (new DocsCacheCalibrator)->calibrate([], [0.5], 0.85);
})->throws(RagEvalDataException::class);

it('AC-7 computes the cosine of two vectors', function (): void {
    expect(DocsCacheCalibrator::cosine([1.0, 0.0], [0.0, 1.0]))->toBe(0.0)
        ->and(DocsCacheCalibrator::cosine([2.0, 0.0], [1.0, 0.0]))->toBe(1.0)
        ->and(DocsCacheCalibrator::cosine([0.0, 0.0], [1.0, 0.0]))->toBe(0.0);
});

it('NFR-2 loads the committed pair set with at least 20 pairs per label and different intent words in every near-miss', function (): void {
    $pairs = (new DocsCachePairSet)->load();
    $words = fn (string $text): array => array_values(array_unique(str_word_count(mb_strtolower($text), 1)));

    $paraphrases = array_filter($pairs, fn ($pair): bool => $pair->isParaphrase());
    $nearMisses = array_filter($pairs, fn ($pair): bool => ! $pair->isParaphrase());

    expect(count($paraphrases))->toBeGreaterThanOrEqual(20)
        ->and(count($nearMisses))->toBeGreaterThanOrEqual(20);

    foreach ($nearMisses as $pair) {
        expect(array_diff($words($pair->a), $words($pair->b)))->not->toBe([], "{$pair->id} has no word that only a uses")
            ->and(array_diff($words($pair->b), $words($pair->a)))->not->toBe([], "{$pair->id} has no word that only b uses");
    }
});

it('AC-8 suggests a default strictly above the highest near-miss even when floating point rounding lands on it', function (float $highest): void {
    $calibration = (new DocsCacheCalibrator)->calibrate([0.99, 0.5], [$highest, 0.4], 0.85)->toArray();

    expect($calibration['suggested_default']['threshold'])->toBeGreaterThan($highest)
        ->and($calibration['suggested_default']['fpr'])->toEqual(0.0);
})->with([
    'just above a 2-decimal value' => [0.930000004],
    'a 2-decimal value' => [0.93],
    'just below a 2-decimal value' => [0.929999999],
    'float noise' => [0.57],
    'the product 0.29 * 100' => [0.29],
]);

it('AC-7 compares with at-or-above exactly as the lookup does, without an epsilon', function (): void {
    $calibration = (new DocsCacheCalibrator)->calibrate([0.9, 0.85], [0.7], 0.9)->toArray();
    $justBelow = (new DocsCacheCalibrator)->calibrate([0.9 - 1e-12, 0.5], [0.4], 0.9)->toArray();

    expect($calibration['configured']['tpr'])->toEqual(0.5)
        ->and($justBelow['configured']['tpr'])->toEqual(0.0);
});
