<?php

declare(strict_types=1);

use App\Ai\ModerationAgent;
use App\DTOs\ModerationEvalOutcome;
use App\DTOs\ModerationFactsResult;
use App\DTOs\ModerationResult;
use App\DTOs\ModerationSetItem;
use App\Exceptions\RagEvalSetException;
use App\Services\ModerationEvalScorer;
use App\Services\ModerationEvalSet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const MODERATION_COMMITTED_RUN = 'bench/results/moderation/2026-11-12/run.json';

function moderationEvalRoot(): string
{
    return storage_path('framework/testing/moderation-eval');
}

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    config(['ai.docs.eval_results_root' => moderationEvalRoot()]);
    config(['ai.default' => 'fake-stream', 'ai.moderation.thresholds' => [], 'ai.moderation.threshold' => 0.5]);
    File::deleteDirectory(moderationEvalRoot());

    File::ensureDirectoryExists(moderationEvalRoot());
});

afterEach(function (): void {
    File::deleteDirectory(moderationEvalRoot());
});

/**
 * @param  list<string>  $expected
 * @param  list<string>  $reasons
 */
function moderationEvalOutcome(string $id, array $expected, ?float $risk, array $reasons = [], string $status = ModerationResult::CLASSIFIED, int $attempts = 1, int $latencyMs = 100, ?string $kind = null): ModerationEvalOutcome
{
    $kind ??= $expected === [] && (int) substr($id, 4) > 35 ? 'hard_negative' : null;
    $item = new ModerationSetItem($id, 'Title', 'Volkswagen', 'Golf', 2018, 5000, 90000, 'Berlin', 'Text', $expected === [] ? 'safe' : 'risky', $expected, $kind ?? ($expected === [] ? 'safe' : 'risky'));
    $classified = $status === ModerationResult::CLASSIFIED;
    $result = new ModerationResult($status, $risk, $classified ? $risk >= 0.5 : null, $reasons, $classified ? 'Because.' : null, new ModerationFactsResult(null, null, [], false, null), 0.5, 'fake-stream', 'fake-model', 'moderation@v1', $attempts, []);

    return new ModerationEvalOutcome($item, $result, $latencyMs);
}

/**
 * Nine scripted answers: four safe (two of them hard negatives), three risky, one invalid and one failed.
 *
 * @return list<ModerationEvalOutcome>
 */
function moderationEvalScript(): array
{
    return [
        moderationEvalOutcome('mod-001', [], 0.1, latencyMs: 100),
        moderationEvalOutcome('mod-002', [], 0.6, latencyMs: 200),
        moderationEvalOutcome('mod-036', [], 0.7, latencyMs: 300),
        moderationEvalOutcome('mod-037', [], 0.2, latencyMs: 400),
        moderationEvalOutcome('mod-051', ['advance_payment'], 0.9, ['advance_payment'], latencyMs: 500),
        moderationEvalOutcome('mod-052', ['off_platform_contact', 'other'], 0.8, ['off_platform_contact'], attempts: 2, latencyMs: 600),
        moderationEvalOutcome('mod-053', ['reported_vin'], 0.4, [], latencyMs: 700),
        moderationEvalOutcome('mod-054', ['advance_payment'], null, status: ModerationResult::INVALID, attempts: 2, latencyMs: 800),
        moderationEvalOutcome('mod-055', [], null, status: ModerationResult::FAILED, latencyMs: 900),
    ];
}

it('AC-6 counts the JSON-valid rates, leaving rejected and failed answers out of the classification', function (): void {
    $report = (new ModerationEvalScorer)->score('fake-model', moderationEvalScript());

    expect($report['items'])->toBe(9)
        ->and($report['json_valid_first_try'])->toBe(['found' => 6, 'of' => 9, 'rate' => 0.6667])
        ->and($report['json_valid_after_retry'])->toBe(['found' => 7, 'of' => 9, 'rate' => 0.7778])
        ->and($report['invalid'])->toBe(1)
        ->and($report['failed'])->toBe(1)
        ->and($report['scored'])->toBe(7);
});

it('AC-6 computes accuracy, precision, recall and F1 at the configured threshold, apart from the best-F1 figures', function (): void {
    $atThreshold = (new ModerationEvalScorer)->score('fake-model', moderationEvalScript())['at_configured_threshold'];

    expect($atThreshold)->toBe(['tp' => 2, 'fp' => 3, 'fn' => 2, 'tn' => 2, 'accuracy' => 0.4444, 'precision' => 0.4, 'recall' => 0.5, 'f1' => 0.4444]);
});

it('AC-6 builds a 17-point threshold curve from 0.1 to 0.9', function (): void {
    $curve = (new ModerationEvalScorer)->score('fake-model', moderationEvalScript())['threshold_curve'];

    expect($curve)->toHaveCount(17)
        ->and($curve[0]['threshold'])->toBe(0.1)
        ->and($curve[16]['threshold'])->toBe(0.9);
});

it('AC-6 counts an invalid risky item as a false negative and an invalid safe item as a false positive', function (): void {
    $outcomes = [
        moderationEvalOutcome('mod-001', [], 0.1),
        moderationEvalOutcome('mod-002', [], null, status: ModerationResult::INVALID, attempts: 2),
        moderationEvalOutcome('mod-051', ['advance_payment'], 0.9, ['advance_payment']),
        moderationEvalOutcome('mod-052', ['advance_payment'], null, status: ModerationResult::INVALID, attempts: 2),
    ];
    $report = (new ModerationEvalScorer)->score('fake-model', $outcomes);

    expect($report['at_configured_threshold'])->toBe(['tp' => 1, 'fp' => 1, 'fn' => 1, 'tn' => 1, 'accuracy' => 0.5, 'precision' => 0.5, 'recall' => 0.5, 'f1' => 0.5])
        ->and($report['threshold_curve'][8]['threshold'])->toBe(0.5)
        ->and($report['threshold_curve'][8]['fp'])->toBe(1)
        ->and($report['threshold_curve'][8]['fn'])->toBe(1)
        ->and($report['threshold_curve'][8]['accuracy'])->toBe(0.5);
});

it('AC-6 lets the highest threshold win when several tie on F1', function (): void {
    $outcomes = [
        moderationEvalOutcome('mod-001', [], 0.05),
        moderationEvalOutcome('mod-051', ['advance_payment'], 0.95, ['advance_payment']),
    ];
    $best = (new ModerationEvalScorer)->score('fake-model', $outcomes)['best_f1'];

    expect($best['f1'])->toBe(1.0)
        ->and($best['threshold'])->toBe(0.9);
});

it('AC-6 picks the best F1 on the scripted run', function (): void {
    $report = (new ModerationEvalScorer)->score('fake-model', moderationEvalScript());

    expect($report['best_f1']['threshold'])->toBe(0.4)
        ->and($report['best_f1']['f1'])->toBe(0.6);
});

it('AC-6 reads the headline figures and the hard-negative false positives at the best-F1 threshold, not the configured one', function (): void {
    $report = (new ModerationEvalScorer)->score('fake-model', moderationEvalScript());

    // Best F1 is at 0.4: risk >= 0.4 flags mod-002, mod-036, mod-051, mod-052 and mod-053; the unanswered safe item is a false positive.
    expect($report['best_f1'])->toMatchArray(['threshold' => 0.4, 'tp' => 3, 'fp' => 3, 'fn' => 1, 'tn' => 2])
        ->and($report['best_f1']['recall'])->toBe(0.75)
        ->and($report['false_positives_on_hard_negatives'])->toBe(['count' => 2, 'of' => 3, 'ids' => ['mod-036', 'mod-055']])
        ->and($report['threshold'])->toBe(0.5);
});

it('AC-6 counts a hard negative rated 0.45 as a false positive at a best-F1 threshold of 0.45 but not at the configured 0.5', function (): void {
    $outcomes = [
        moderationEvalOutcome('mod-001', [], 0.1),
        moderationEvalOutcome('mod-036', [], 0.45),
        moderationEvalOutcome('mod-051', ['advance_payment'], 0.9, ['advance_payment']),
        moderationEvalOutcome('mod-052', ['advance_payment'], 0.45, ['advance_payment']),
    ];
    $report = (new ModerationEvalScorer)->score('fake-model', $outcomes);

    expect($report['best_f1']['threshold'])->toBe(0.45)
        ->and($report['false_positives_on_hard_negatives'])->toBe(['count' => 1, 'of' => 1, 'ids' => ['mod-036']])
        ->and($report['false_positives_on_hard_negatives_at_configured_threshold'])->toBe(['count' => 0, 'of' => 1, 'ids' => []])
        ->and($report['best_f1']['recall'])->toBe(1.0)
        ->and($report['at_configured_threshold']['recall'])->toBe(0.5);
});

it('AC-6 keeps the hard-negative false positives at the configured threshold in their own field', function (): void {
    $report = (new ModerationEvalScorer)->score('fake-model', moderationEvalScript());

    expect($report['false_positives_on_hard_negatives_at_configured_threshold'])->toBe(['count' => 2, 'of' => 3, 'ids' => ['mod-036', 'mod-055']]);
});

it('AC-6 counts a hard negative as a false positive at the best-F1 threshold when it was never answered', function (): void {
    $outcomes = [
        moderationEvalOutcome('mod-036', [], null, status: ModerationResult::INVALID, attempts: 2),
        moderationEvalOutcome('mod-051', ['advance_payment'], 0.9, ['advance_payment']),
        moderationEvalOutcome('mod-001', [], 0.1),
    ];
    $report = (new ModerationEvalScorer)->score('fake-model', $outcomes);

    expect($report['false_positives_on_hard_negatives'])->toBe(['count' => 1, 'of' => 1, 'ids' => ['mod-036']]);
});

it('AC-6 calibrates no threshold when no point of the curve has an F1', function (): void {
    $outcomes = [
        moderationEvalOutcome('mod-001', [], 0.1),
        moderationEvalOutcome('mod-002', [], 0.2),
    ];
    $report = (new ModerationEvalScorer)->score('fake-model', $outcomes);

    expect($report['best_f1'])->toBeNull()
        ->and($report['false_positives_on_hard_negatives'])->toBeNull();
});

it('AC-6 skips a curve point without an F1 when picking the best one', function (): void {
    // At 0.1 to 0.2 every item is flagged. From 0.55 up nothing is flagged, so precision and F1 are null there.
    $outcomes = [
        moderationEvalOutcome('mod-001', [], 0.05),
        moderationEvalOutcome('mod-051', ['advance_payment'], 0.5, ['advance_payment']),
    ];
    $best = (new ModerationEvalScorer)->score('fake-model', $outcomes)['best_f1'];

    expect($best['f1'])->toBe(1.0)->and($best['threshold'])->toBe(0.5);
});

it('AC-6 divides the hard-negative false positives by all 15 and counts invalid items as false positives', function (): void {
    $outcomes = [];
    foreach (range(36, 50) as $number) {
        $id = sprintf('mod-%03d', $number);
        $outcomes[] = $number === 40
            ? moderationEvalOutcome($id, [], null, status: ModerationResult::INVALID, attempts: 2)
            : moderationEvalOutcome($id, [], $number === 41 ? 0.9 : 0.1);
    }
    $outcomes[] = moderationEvalOutcome('mod-051', ['advance_payment'], 0.95, ['advance_payment']);
    $report = (new ModerationEvalScorer)->score('fake-model', $outcomes);

    expect($report['false_positives_on_hard_negatives'])->toBe(['count' => 2, 'of' => 15, 'ids' => ['mod-040', 'mod-041']]);
});

it('AC-6 computes reason recall overall and per reason', function (): void {
    $recall = (new ModerationEvalScorer)->score('fake-model', moderationEvalScript())['reason_recall'];

    expect($recall['overall'])->toBe(['found' => 2, 'of' => 4, 'rate' => 0.5])
        ->and($recall['by_reason']['advance_payment']['rate'])->toBe(1.0)
        ->and($recall['by_reason']['off_platform_contact']['rate'])->toBe(1.0)
        ->and($recall['by_reason']['other']['rate'])->toBe(0.0)
        ->and($recall['by_reason']['reported_vin']['rate'])->toBe(0.0)
        ->and($recall['by_reason'])->not->toHaveKey('pressure_tactics');
});

it('AC-6 reports the latency percentiles of every item', function (): void {
    $latency = (new ModerationEvalScorer)->score('fake-model', moderationEvalScript())['latency_ms'];

    expect($latency)->toBe(['p50' => 500, 'p95' => 900, 'mean' => 500.0]);
});

it('AC-6 handles an empty run without dividing by zero', function (): void {
    $report = (new ModerationEvalScorer)->score('fake-model', []);

    expect($report['json_valid_first_try']['rate'])->toBeNull()
        ->and($report['best_f1'])->toBeNull()
        ->and($report['latency_ms']['p50'])->toBeNull();
});

it('AC-6 reads the hard negatives from the kind field, not from the id', function (): void {
    $row = ['id' => 'mod-001', 'title' => 'Golf', 'make' => 'Volkswagen', 'model' => 'Golf', 'year' => 2018, 'price_eur' => 5000, 'mileage_km' => 90000, 'city' => 'Berlin', 'description' => 'Fine car.', 'label' => 'safe', 'kind' => 'hard_negative', 'reasons' => []];
    File::put(moderationEvalRoot().'/kind.jsonl', json_encode($row)."\n");
    $items = (new ModerationEvalSet)->load(moderationEvalRoot().'/kind.jsonl');

    expect(ModerationEvalSet::isHardNegative($items[0]))->toBeTrue();

    File::put(moderationEvalRoot().'/kind.jsonl', json_encode(['kind' => 'mixed'] + $row)."\n");

    expect(fn () => (new ModerationEvalSet)->load(moderationEvalRoot().'/kind.jsonl'))->toThrow(RagEvalSetException::class, 'kind');
});

it('AC-6 loads the committed set and rejects a malformed line', function (): void {
    $items = (new ModerationEvalSet)->load();

    expect($items)->toHaveCount(100)
        ->and(count(array_filter($items, ModerationEvalSet::isHardNegative(...))))->toBe(15);

    File::ensureDirectoryExists(moderationEvalRoot());
    File::put(moderationEvalRoot().'/bad.jsonl', '{"id":"mod-001"}'."\n");

    expect(fn () => (new ModerationEvalSet)->load(moderationEvalRoot().'/bad.jsonl'))->toThrow(RagEvalSetException::class);
});

it('AC-6 runs moderation:eval on a set and writes run.json', function (): void {
    config(['ai.moderation.thresholds' => ['model-a' => 0.5]]);
    ModerationAgent::fake(fn (): array => ['risk' => 0.9, 'reasons' => ['advance_payment'], 'explanation' => 'Asks for a deposit.']);

    $rows = [
        ['id' => 'mod-001', 'title' => 'Golf', 'make' => 'Volkswagen', 'model' => 'Golf', 'year' => 2018, 'price_eur' => 5000, 'mileage_km' => 90000, 'city' => 'Berlin', 'description' => 'Fine car.', 'label' => 'safe', 'kind' => 'safe', 'reasons' => []],
        ['id' => 'mod-002', 'title' => 'Golf', 'make' => 'Volkswagen', 'model' => 'Golf', 'year' => 2018, 'price_eur' => 5000, 'mileage_km' => 90000, 'city' => 'Berlin', 'description' => 'Pay a deposit first.', 'label' => 'risky', 'kind' => 'risky', 'reasons' => ['advance_payment']],
    ];
    File::ensureDirectoryExists(moderationEvalRoot());
    File::put(moderationEvalRoot().'/set.jsonl', implode("\n", array_map('json_encode', $rows))."\n");
    $directory = moderationEvalRoot().'/out';

    $this->artisan('moderation:eval', ['--set' => moderationEvalRoot().'/set.jsonl', '--models' => 'model-a', '--output-dir' => $directory])->expectsOutputToContain('Results')->assertSuccessful();

    $run = json_decode((string) File::get($directory.'/run.json'), true);

    expect($run['metadata']['items'])->toBe(2)
        ->and($run['summary']['model-a']['at_configured_threshold'])->toMatchArray(['tp' => 1, 'fp' => 1, 'fn' => 0, 'tn' => 0])
        ->and($run['summary']['model-a']['false_positives_on_hard_negatives'])->toBe(['count' => 0, 'of' => 0, 'ids' => []])
        ->and($run['results']['model-a'])->toHaveCount(2);

    ModerationAgent::assertPromptedTimes(2);
    $this->artisan('moderation:eval', ['--set' => moderationEvalRoot().'/set.jsonl', '--models' => 'model-a', '--output-dir' => $directory])->assertFailed();
});

it('AC-6 prints "no threshold calibrated" when a model has no best F1', function (): void {
    ModerationAgent::fake(fn (): array => ['risk' => 0.1, 'reasons' => [], 'explanation' => 'Looks fine.']);
    $row = ['id' => 'mod-001', 'title' => 'Golf', 'make' => 'Volkswagen', 'model' => 'Golf', 'year' => 2018, 'price_eur' => 5000, 'mileage_km' => 90000, 'city' => 'Berlin', 'description' => 'Fine car.', 'label' => 'safe', 'kind' => 'safe', 'reasons' => []];
    File::put(moderationEvalRoot().'/set.jsonl', json_encode($row)."\n");

    $this->artisan('moderation:eval', ['--set' => moderationEvalRoot().'/set.jsonl', '--models' => 'model-a', '--output-dir' => moderationEvalRoot().'/out'])
        ->expectsOutputToContain('no threshold calibrated')
        ->assertSuccessful();

    expect(json_decode((string) File::get(moderationEvalRoot().'/out/run.json'), true)['summary']['model-a']['best_f1'])->toBeNull();
});

it('AC-6 files the pinned model of --provider under the model that ran, with its own threshold', function (): void {
    config([
        'ai.features.moderation.provider' => 'fake-stream',
        'ai.features.moderation.model' => 'configured-model',
        'ai.moderation.thresholds' => ['model-x' => 0.3],
    ]);
    $row = ['id' => 'mod-001', 'title' => 'Golf', 'make' => 'Volkswagen', 'model' => 'Golf', 'year' => 2018, 'price_eur' => 5000, 'mileage_km' => 90000, 'city' => 'Berlin', 'description' => 'Pay a deposit first.', 'label' => 'risky', 'kind' => 'risky', 'reasons' => ['advance_payment']];
    File::put(moderationEvalRoot().'/set.jsonl', json_encode($row)."\n");
    ModerationAgent::fake(fn (): array => ['risk' => 0.4, 'reasons' => ['advance_payment'], 'explanation' => 'Asks for a deposit.']);

    $this->artisan('moderation:eval', ['--provider' => 'ollama', '--set' => moderationEvalRoot().'/set.jsonl', '--models' => 'model-x', '--output-dir' => moderationEvalRoot().'/out'])->assertSuccessful();

    $run = json_decode((string) File::get(moderationEvalRoot().'/out/run.json'), true);

    expect($run['metadata']['provider'])->toBe('ollama')
        ->and($run['summary']['model-x']['threshold'])->toBe(0.3)
        ->and($run['summary']['model-x']['at_configured_threshold']['tp'])->toBe(1);
});

it('AC-6 runs moderation:eval with --models=a,b and writes a summary with its own threshold and curve for each', function (): void {
    config(['ai.moderation.thresholds' => ['model-a' => 0.3, 'model-b' => 0.7]]);
    $answered = [];
    ModerationAgent::fake(function () use (&$answered): array {
        $answered[] = 1;

        return ['risk' => 0.5, 'reasons' => ['advance_payment'], 'explanation' => 'Asks for a deposit.'];
    });

    $row = ['id' => 'mod-001', 'title' => 'Golf', 'make' => 'Volkswagen', 'model' => 'Golf', 'year' => 2018, 'price_eur' => 5000, 'mileage_km' => 90000, 'city' => 'Berlin', 'description' => 'Pay a deposit first.', 'label' => 'risky', 'kind' => 'risky', 'reasons' => ['advance_payment']];
    File::put(moderationEvalRoot().'/set.jsonl', json_encode($row)."\n");
    $directory = moderationEvalRoot().'/out';

    $this->artisan('moderation:eval', ['--set' => moderationEvalRoot().'/set.jsonl', '--models' => 'model-a,model-b', '--output-dir' => $directory])->assertSuccessful();

    $run = json_decode((string) File::get($directory.'/run.json'), true);

    expect($run['metadata']['models'])->toBe(['model-a', 'model-b'])
        ->and(array_keys($run['summary']))->toBe(['model-a', 'model-b'])
        ->and($run['summary']['model-a']['model'])->toBe('model-a')
        ->and($run['summary']['model-a']['threshold'])->toBe(0.3)
        ->and($run['summary']['model-b']['threshold'])->toBe(0.7)
        ->and($run['summary']['model-a']['threshold_curve'])->toHaveCount(17)
        ->and($run['summary']['model-b']['threshold_curve'])->toHaveCount(17)
        ->and($run['summary']['model-a']['at_configured_threshold']['tp'])->toBe(1)
        ->and($run['summary']['model-b']['at_configured_threshold']['fn'])->toBe(1)
        ->and(array_keys($run['results']))->toBe(['model-a', 'model-b']);
});

it('AC-6 keeps ai.moderation.thresholds equal to the best F1 threshold of each model in the committed run', function (): void {
    $summaries = json_decode((string) file_get_contents(base_path(MODERATION_COMMITTED_RUN)), true, flags: JSON_THROW_ON_ERROR)['summary'];
    $thresholds = require config_path('ai.php');

    expect(array_keys($summaries))->toBe(['qwen3.5:9b', 'gemma4:12b']);

    foreach ($summaries as $model => $summary) {
        expect($summary['model'])->toBe($model)
            ->and($summary['threshold_curve'])->toHaveCount(17)
            ->and($thresholds['moderation']['thresholds'][$model])->toBe($summary['best_f1']['threshold']);
    }
});
