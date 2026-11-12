<?php

declare(strict_types=1);

use App\Ai\PhotoBestPickAgent;
use App\Contracts\ListingPhotoRepositoryInterface;
use App\DTOs\DuplicateEvalOutcome;
use App\DTOs\DuplicateEvalPhoto;
use App\DTOs\DuplicateGroupDraft;
use App\DTOs\DuplicateMember;
use App\DTOs\PhotoEmbedding;
use App\Models\AiRun;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Services\DuplicateEvalScorer;
use App\Services\DuplicateEvalService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const DUPEVAL_LABEL = 'photo-encoder/openai/clip-vit-base-patch32';

function dupEvalRoot(): string
{
    return storage_path('framework/testing/duplicate-eval');
}

beforeEach(function (): void {
    File::ensureDirectoryExists(dupEvalRoot());
    config([
        'ai.docs.eval_results_root' => dupEvalRoot(),
        'ai.features.embeddings-photos.provider' => 'photo-encoder',
        'ai.features.embeddings-photos.model' => null,
        'ai.photo_duplicates.max_group' => 6,
    ]);
    Carbon::setTestNow('2026-11-12 10:00:00');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Carbon::setTestNow();
    File::deleteDirectory(dupEvalRoot());
});

function dupPhoto(int $id, ?int $original = null, ?string $variant = null): DuplicateEvalPhoto
{
    return new DuplicateEvalPhoto($id, $original ?? $id, $variant, "photos/{$id}.jpg", 1024, 768);
}

/**
 * Original 1 with copies 2 (downscaled) and 3 (mirrored), original 10 with copy 11 (cropped), and the imported photo 20.
 *
 * @return list<DuplicateEvalPhoto>
 */
function dupCraftedPhotos(): array
{
    return [dupPhoto(1), dupPhoto(2, 1, 'downscaled'), dupPhoto(3, 1, 'mirrored'), dupPhoto(10), dupPhoto(11, 10, 'cropped'), dupPhoto(20)];
}

/**
 * @param  list<array{0: int, 1: int, 2: float}>  $edges
 * @return array<string, array{0: int, 1: int, 2: float}>
 */
function dupEdges(array $edges): array
{
    $keyed = [];

    foreach ($edges as $edge) {
        $keyed["{$edge[0]}:{$edge[1]}"] = $edge;
    }

    return $keyed;
}

/**
 * @param  list<array<string, mixed>>  $curve
 * @return array<string, mixed>
 */
function dupRow(array $curve, float $threshold): array
{
    return collect($curve)->firstWhere('threshold', $threshold);
}

it('AC-7 counts true and false pairs, recall, precision and F1 at each threshold on crafted groups', function (): void {
    $scorer = new DuplicateEvalScorer;
    $edges = dupEdges([[1, 2, 0.99], [1, 3, 0.93], [2, 3, 0.92], [10, 11, 0.96], [1, 20, 0.91], [10, 20, 0.90]]);

    $curve = $scorer->curve(dupCraftedPhotos(), $edges, [0.90, 0.91, 0.92, 0.93, 0.97]);

    expect($scorer->truePairs(dupCraftedPhotos()))->toBe(4)
        ->and(dupRow($curve, 0.90))->toMatchArray(['pairs' => 6, 'true_pairs' => 4, 'false_pairs' => 2, 'precision' => 0.6667, 'recall' => 1.0, 'f1' => 0.8])
        ->and(dupRow($curve, 0.91))->toMatchArray(['pairs' => 5, 'false_pairs' => 1, 'precision' => 0.8, 'recall' => 1.0])
        ->and(dupRow($curve, 0.92))->toMatchArray(['pairs' => 4, 'true_pairs' => 4, 'false_pairs' => 0, 'precision' => 1.0, 'recall' => 1.0, 'f1' => 1.0])
        ->and(dupRow($curve, 0.93))->toMatchArray(['true_pairs' => 3, 'recall' => 0.75])
        ->and(dupRow($curve, 0.97))->toMatchArray(['pairs' => 1, 'true_pairs' => 1, 'recall' => 0.25, 'precision' => 1.0]);
});

it('AC-7 reports recall per kind of copy over original-with-copy pairs only', function (): void {
    $scorer = new DuplicateEvalScorer;
    $edges = dupEdges([[1, 2, 0.99], [1, 3, 0.93], [2, 3, 0.99], [10, 11, 0.96]]);

    $curve = $scorer->curve(dupCraftedPhotos(), $edges, [0.95, 0.97]);

    expect($scorer->pairsByKind(dupCraftedPhotos()))->toBe(['cropped' => 1, 'downscaled' => 1, 'mirrored' => 1])
        ->and(dupRow($curve, 0.95)['recall_by_kind'])->toBe([
            'cropped' => ['found' => 1, 'total' => 1, 'recall' => 1.0],
            'downscaled' => ['found' => 1, 'total' => 1, 'recall' => 1.0],
            'mirrored' => ['found' => 0, 'total' => 1, 'recall' => 0.0],
        ])
        ->and(dupRow($curve, 0.97)['recall_by_kind']['cropped'])->toBe(['found' => 0, 'total' => 1, 'recall' => 0.0])
        ->and(dupRow($curve, 0.95)['true_pairs'])->toBe(3);
});

it('AC-7 treats no edge as precision 1 and recall 0', function (): void {
    $curve = (new DuplicateEvalScorer)->curve(dupCraftedPhotos(), [], [0.95]);

    expect($curve[0])->toMatchArray(['pairs' => 0, 'precision' => 1.0, 'recall' => 0.0, 'f1' => 0.0]);
});

it('AC-7 chooses the highest threshold with recall of 0.9 and no false pair', function (): void {
    $scorer = new DuplicateEvalScorer;
    $edges = dupEdges([[1, 2, 0.99], [1, 3, 0.93], [2, 3, 0.92], [10, 11, 0.96], [1, 20, 0.91], [10, 20, 0.90]]);

    $chosen = $scorer->chooseCutoff($scorer->curve(dupCraftedPhotos(), $edges, DuplicateEvalService::THRESHOLDS));

    expect($chosen)->toBe(['threshold' => 0.92, 'rule' => 'recall_and_precision']);
});

it('AC-7 falls back to the best F1, the higher threshold on a tie, when no threshold is clean', function (): void {
    $scorer = new DuplicateEvalScorer;
    $photos = [dupPhoto(1), dupPhoto(2, 1, 'cropped'), dupPhoto(3), dupPhoto(4, 3, 'darkened')];
    $edges = dupEdges([[1, 2, 0.99], [3, 4, 0.91], [1, 3, 0.95]]);

    $curve = $scorer->curve($photos, $edges, DuplicateEvalService::THRESHOLDS);

    expect(dupRow($curve, 0.90)['f1'])->toBe(0.8)
        ->and(dupRow($curve, 0.91)['f1'])->toBe(0.8)
        ->and(dupRow($curve, 0.95)['f1'])->toBe(0.5)
        ->and(dupRow($curve, 0.96)['f1'])->toBe(0.666667)
        ->and(array_filter($curve, fn (array $row): bool => $row['recall'] >= 0.9 && $row['false_pairs'] === 0))->toBe([])
        ->and($scorer->chooseCutoff($curve))->toBe(['threshold' => 0.91, 'rule' => 'f1_fallback']);
});

it('AC-7 does not count a threshold with recall below 0.9 as clean even without false pairs', function (): void {
    $scorer = new DuplicateEvalScorer;
    $edges = dupEdges([[1, 2, 0.99], [10, 11, 0.96]]);

    $chosen = $scorer->chooseCutoff($scorer->curve(dupCraftedPhotos(), $edges, DuplicateEvalService::THRESHOLDS));

    expect($chosen['rule'])->toBe('f1_fallback')
        ->and($chosen['threshold'])->toBe(0.96);
});

it('AC-7 judges only groups with one original and its copies and counts the rest by reason', function (): void {
    $photos = [...dupCraftedPhotos(), dupPhoto(30), dupPhoto(31, 30, 'darkened')];
    $group = fn (int ...$ids): DuplicateGroupDraft => new DuplicateGroupDraft(array_map(fn (int $id): DuplicateMember => new DuplicateMember($id, 0.99), $ids), false);

    $result = (new DuplicateEvalScorer)->classifyGroups([
        $group(1, 2, 3),
        $group(30, 31),
        $group(2, 3),
        $group(1, 10),
        $group(1, 11),
        $group(20, 3),
    ], $photos);

    expect(array_map(fn (array $members): array => array_map(fn (DuplicateEvalPhoto $photo): int => $photo->id, $members), $result['judged']))
        ->toBe([[1, 2, 3], [30, 31]])
        ->and($result['skipped'])->toBe(['no_original' => 1, 'several_originals' => 1, 'foreign_copies' => 2]);
});

it('AC-7 scores the pick per model, counting an invalid answer as a miss', function (): void {
    $outcomes = [
        new DuplicateEvalOutcome(1, 1, 1, 100),
        new DuplicateEvalOutcome(10, 2, 11, 400),
        new DuplicateEvalOutcome(20, 3, null, 900),
        new DuplicateEvalOutcome(30, 1, 30, 200),
    ];

    $report = (new DuplicateEvalScorer)->pickAccuracy('gemma4:12b', $outcomes);

    expect($report['groups'])->toBe(4)
        ->and($report['picked_original'])->toBe(['count' => 2, 'rate' => 0.5])
        ->and($report['valid_first_attempt'])->toBe(['count' => 2, 'rate' => 0.5])
        ->and($report['valid_after_retries'])->toBe(['count' => 3, 'rate' => 0.75])
        ->and($report['mean_attempts'])->toBe(1.75)
        ->and($report['latency_ms'])->toBe(['p50' => 200, 'p95' => 900]);
});

it('AC-7 handles a model with no judged group', function (): void {
    $report = (new DuplicateEvalScorer)->pickAccuracy('m', []);

    expect($report['picked_original']['rate'])->toBe(0.0)
        ->and($report['latency_ms'])->toBe(['p50' => null, 'p95' => null]);
});

/**
 * A unit vector turned by the given degrees inside the plane of an axis pair; two vectors of one pair have the cosine
 * of the angle between them as their similarity, vectors of different pairs are orthogonal.
 *
 * @return list<float>
 */
function dupVector(int $axis, float $degrees): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[2 * $axis] = cos(deg2rad($degrees));
    $vector[2 * $axis + 1] = sin(deg2rad($degrees));

    return $vector;
}

/**
 * @param  list<float>  $vector
 */
function dupStored(array $vector, ?ListingPhoto $original = null, ?string $variant = null): ListingPhoto
{
    $photo = ListingPhoto::factory()->create([
        'listing_id' => $original?->listing_id ?? Listing::factory()->create()->id,
        'source' => $original === null ? ListingPhoto::SOURCE_STANFORD_CARS : 'augmented',
        'duplicate_of' => $original?->id,
        'variant' => $variant,
    ]);
    app(ListingPhotoRepositoryInterface::class)->storeEmbeddings([new PhotoEmbedding($photo->id, $vector)], DUPEVAL_LABEL, CarbonImmutable::now());

    return $photo;
}

/**
 * Original A (axis 0) with copies at 1 and 4 degrees, original B (axis 1) with a copy at 9 degrees, and an imported
 * lookalike C of A, 20 degrees away. The false pairs with C stay under 0.97, so 0.98 is the chosen cutoff.
 *
 * @return array{a: ListingPhoto, a1: ListingPhoto, a2: ListingPhoto, b: ListingPhoto, b1: ListingPhoto, c: ListingPhoto}
 */
function dupScene(): array
{
    $a = dupStored(dupVector(0, 0));
    $b = dupStored(dupVector(1, 0));

    return [
        'a' => $a,
        'a1' => dupStored(dupVector(0, 1), $a, 'downscaled'),
        'a2' => dupStored(dupVector(0, 4), $a, 'cropped'),
        'b' => $b,
        'b1' => dupStored(dupVector(1, 9), $b, 'mirrored'),
        'c' => dupStored(dupVector(0, 20)),
    ];
}

/**
 * @return array<string, mixed>
 */
function dupRun(): array
{
    return json_decode((string) File::get(dupEvalRoot().'/photo-duplicates/2026-11-12/run.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  list<ListingPhoto>  $photos
 * @return array<string, mixed>
 */
function dupPick(array $photos, int $bestId): array
{
    return [
        'best_photo_id' => $bestId,
        'reasons' => ['Sharpest.'],
        'issues' => array_values(array_map(
            fn (ListingPhoto $photo): array => ['photo_id' => $photo->id, 'problem' => 'compression'],
            array_filter($photos, fn (ListingPhoto $photo): bool => $photo->id !== $bestId),
        )),
    ];
}

it('AC-7 writes the curve, the cutoff, the clustering time and the pick accuracy to run.json', function (): void {
    $scene = dupScene();
    PhotoBestPickAgent::fake([
        dupPick([$scene['a'], $scene['a1'], $scene['a2']], $scene['a']->id),
        dupPick([$scene['b'], $scene['b1']], $scene['b1']->id),
    ]);

    $this->artisan('photos:duplicates-eval', ['--models' => 'gemma4:12b'])->assertExitCode(0);

    $run = dupRun();
    $at98 = collect($run['curve'])->firstWhere('threshold', 0.98);

    expect($run['metadata'])->toMatchArray(['photos' => 6, 'originals' => 3, 'copies' => 3, 'true_pairs' => 4, 'prompt_version' => 'photo-best-pick@v1', 'label' => DUPEVAL_LABEL])
        ->and(array_column($run['curve'], 'threshold'))->toBe([0.9, 0.91, 0.92, 0.93, 0.94, 0.95, 0.96, 0.97, 0.98, 0.99])
        ->and($at98)->toMatchArray(['true_pairs' => 4, 'false_pairs' => 0, 'precision' => 1.0, 'recall' => 1.0])
        ->and($at98['recall_by_kind'])->toHaveKeys(['cropped', 'downscaled', 'mirrored'])
        ->and(collect($run['curve'])->firstWhere('threshold', 0.9)['false_pairs'])->toBeGreaterThan(0)
        ->and($run['cutoff'])->toBe(['threshold' => 0.98, 'rule' => 'recall_and_precision'])
        ->and($run['groups'])->toMatchArray(['count' => 2, 'split' => 0, 'judged' => 2])
        ->and($run['clustering'])->toHaveKeys(['photos', 'edges_ms', 'cluster_ms', 'clustering_ms'])
        ->and($run['clustering']['photos'])->toBe(6)
        ->and($run['judging']['models'])->toHaveCount(1)
        ->and($run['judging']['models'][0]['model'])->toBe('gemma4:12b')
        ->and($run['judging']['models'][0]['picked_original'])->toBe(['count' => 1, 'rate' => 0.5])
        ->and($run['judging']['models'][0]['valid_first_attempt'])->toEqual(['count' => 2, 'rate' => 1.0])
        ->and($run['judging']['results'])->toHaveCount(2)
        ->and($run['judging']['results'][1])->toMatchArray(['original_id' => $scene['b']->id, 'best_photo_id' => $scene['b1']->id, 'picked_original' => false]);

    PhotoBestPickAgent::assertPromptedTimes(2);
});

it('AC-7 logs every judgement as an eval run and stores no judgement row', function (): void {
    $scene = dupScene();
    PhotoBestPickAgent::fake([
        dupPick([$scene['a'], $scene['a1'], $scene['a2']], $scene['a']->id),
        dupPick([$scene['b'], $scene['b1']], $scene['b']->id),
    ]);

    $this->artisan('photos:duplicates-eval', ['--models' => 'gemma4:12b'])->assertExitCode(0);

    expect(AiRun::count())->toBe(2)
        ->and(AiRun::pluck('origin')->unique()->all())->toBe(['eval'])
        ->and(AiRun::pluck('prompt_version')->unique()->all())->toBe(['photo-best-pick@v1'])
        ->and(DB::table('photo_duplicate_judgements')->count())->toBe(0)
        ->and(DB::table('photo_duplicate_groups')->count())->toBe(0);
});

it('AC-7 retries an answer outside the group and counts the attempts', function (): void {
    $scene = dupScene();
    $outsider = ListingPhoto::factory()->create();
    PhotoBestPickAgent::fake([
        dupPick([$scene['a'], $scene['a1'], $scene['a2']], $outsider->id),
        dupPick([$scene['a'], $scene['a1'], $scene['a2']], $scene['a']->id),
        dupPick([$scene['b'], $scene['b1']], $scene['b']->id),
    ]);

    $this->artisan('photos:duplicates-eval', ['--models' => 'gemma4:12b'])->assertExitCode(0);

    $model = dupRun()['judging']['models'][0];

    expect($model['valid_first_attempt'])->toBe(['count' => 1, 'rate' => 0.5])
        ->and($model['valid_after_retries'])->toEqual(['count' => 2, 'rate' => 1.0])
        ->and($model['picked_original'])->toEqual(['count' => 2, 'rate' => 1.0])
        ->and($model['mean_attempts'])->toBe(1.5);
});

it('AC-7 calibrates without asking a model with --no-judge', function (): void {
    dupScene();
    PhotoBestPickAgent::fake([]);

    $this->artisan('photos:duplicates-eval', ['--no-judge' => true])->assertExitCode(0);

    PhotoBestPickAgent::assertNeverPrompted();
    expect(dupRun()['judging'])->toMatchArray(['models' => [], 'skipped' => []])
        ->and(dupRun()['cutoff']['threshold'])->toBe(0.98);
});

it('AC-7 skips a model that is not installed and records the skip', function (): void {
    $scene = dupScene();
    Http::fake([
        '*/api/tags' => Http::response(['models' => [['name' => 'gemma4:12b']]]),
    ]);
    PhotoBestPickAgent::fake([
        dupPick([$scene['a'], $scene['a1'], $scene['a2']], $scene['a']->id),
        dupPick([$scene['b'], $scene['b1']], $scene['b']->id),
    ]);

    $this->artisan('photos:duplicates-eval')->assertExitCode(0);

    $judging = dupRun()['judging'];

    expect(array_column($judging['models'], 'model'))->toBe(['gemma4:12b'])
        ->and($judging['skipped'])->toBe([['model' => 'qwen3.5:9b', 'reason' => 'not installed as a vision model on this Ollama']]);
});

it('AC-7 fails when no generated copy has a vector and writes nothing', function (): void {
    dupStored(dupVector(0, 0));
    PhotoBestPickAgent::fake([]);

    $this->artisan('photos:duplicates-eval', ['--models' => 'gemma4:12b'])
        ->expectsOutputToContain('No generated copy is embedded')
        ->assertExitCode(1);

    PhotoBestPickAgent::assertNeverPrompted();
    expect(File::exists(dupEvalRoot().'/photo-duplicates'))->toBeFalse();
});

it('AC-7 refuses a second run on the same day without --force', function (): void {
    dupScene();

    $this->artisan('photos:duplicates-eval', ['--no-judge' => true])->assertExitCode(0);
    $first = File::get(dupEvalRoot().'/photo-duplicates/2026-11-12/run.json');

    $this->artisan('photos:duplicates-eval', ['--no-judge' => true])->assertExitCode(2);
    expect(File::get(dupEvalRoot().'/photo-duplicates/2026-11-12/run.json'))->toBe($first);

    $this->artisan('photos:duplicates-eval', ['--no-judge' => true, '--force' => true])->assertExitCode(0);
});

it('AC-7 rejects an empty model list unless --no-judge is given', function (): void {
    $this->artisan('photos:duplicates-eval', ['--models' => ' , '])->assertExitCode(2);
});
