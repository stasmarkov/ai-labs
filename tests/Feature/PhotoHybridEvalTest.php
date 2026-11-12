<?php

declare(strict_types=1);

use App\Contracts\ListingPhotoRepositoryInterface;
use App\DTOs\PhotoEmbedding;
use App\DTOs\PhotoTextEmbedding;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Services\HybridEvalScorer;
use App\Services\SimilarEvalService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const HYBRID_EVAL_LABEL = 'photo-encoder/openai/clip-vit-base-patch32';

function hybridEvalRoot(): string
{
    return storage_path('framework/testing/photo-hybrid-eval');
}

/**
 * A unit vector at the given cosine similarity to [1, 0, 0, ...].
 *
 * @return list<float>
 */
function hybridEvalVector(float $score): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[0] = $score;
    $vector[1] = sqrt(1 - $score * $score);

    return $vector;
}

function hybridEvalPhoto(string $make, string $model, float $image, ?float $caption): ListingPhoto
{
    $photo = ListingPhoto::factory()->create(['listing_id' => Listing::factory()->create(['year' => 2020, 'make' => $make, 'model' => $model])->id]);
    $repository = app(ListingPhotoRepositoryInterface::class);
    $repository->storeEmbeddings([new PhotoEmbedding($photo->id, hybridEvalVector($image))], HYBRID_EVAL_LABEL, CarbonImmutable::now());

    if ($caption !== null) {
        $repository->storeTextEmbeddings([new PhotoTextEmbedding($photo->id, hybridEvalVector($caption), 'hash')], HYBRID_EVAL_LABEL, CarbonImmutable::now());
    }

    return $photo;
}

/**
 * Four photos around a Toyota Corolla query: the best image match is the wrong car, and its caption says so.
 *
 * @return list<ListingPhoto>
 */
function hybridEvalScene(bool $captions = true): array
{
    $c = fn (float $caption): ?float => $captions ? $caption : null;

    return [
        hybridEvalPhoto('Toyota', 'Corolla', 1.0, $c(1.0)),
        hybridEvalPhoto('Toyota', 'Corolla', 0.90, $c(0.90)),
        hybridEvalPhoto('Honda', 'Civic', 0.92, $c(0.10)),
        hybridEvalPhoto('Toyota', 'Corolla', 0.80, $c(0.95)),
        hybridEvalPhoto('Toyota', 'Corolla', 0.70, null),
    ];
}

function hybridEvalFakeTitles(): void
{
    Http::fake(['encoder.test:7997/embeddings' => function (Request $request) {
        $data = array_map(function (int $index) use ($request): array {
            $vector = array_fill(0, 512, 0.0);
            $vector[str_contains((string) $request['input'][$index], 'Toyota') ? 0 : 1] = 1.0;

            return ['index' => $index, 'embedding' => $vector];
        }, array_keys($request['input']));

        return Http::response(['data' => $data]);
    }]);
}

/**
 * @return array<string, mixed>
 */
function hybridEvalRun(): array
{
    return json_decode((string) File::get(hybridEvalRoot().'/photo-similar/2026-11-11/hybrid.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $result
 * @return array<string, mixed>
 */
function hybridEvalRow(array $result, string $path, float $w): array
{
    return collect(data_get($result, $path))->first(fn (array $row): bool => abs($row['w'] - $w) < 1e-9);
}

beforeEach(function (): void {
    File::ensureDirectoryExists(hybridEvalRoot());
    config([
        'ai.docs.eval_results_root' => hybridEvalRoot(),
        'ai.features.embeddings-photos.provider' => 'photo-encoder',
        'ai.features.embeddings-photos.model' => null,
        'ai.providers.photo-encoder.url' => 'http://encoder.test:7997',
        'ai.similar_photos.min_score' => 0.60,
        'ai.similar_photos.hybrid_min_score' => 0.60,
        'ai.similar_photos.near_duplicate_cutoff' => 0.98,
    ]);
    Carbon::setTestNow('2026-11-11 10:00:00');
    Http::preventStrayRequests();
    Cache::flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
    File::deleteDirectory(hybridEvalRoot());
});

it('AC-5 calibrates the minimum score on the middle of the plateau and picks the best weight on known numbers', function (): void {
    $scorer = new HybridEvalScorer;

    expect($scorer->calibrate([0.5, 0.9, 0.9, 0.9, 0.9, 0.4]))->toBe(2)
        ->and($scorer->calibrate([0.5, 0.9, 0.9, 0.9, 0.4]))->toBe(2)
        ->and($scorer->calibrate([0.3, 0.8, 0.5, 0.8, 0.8]))->toBe(1)
        ->and($scorer->calibrate([0.2, 0.7, 0.6995, 0.6]))->toBe(1);

    $baseline = ['precision_make_model' => 0.70, 'coverage' => 0.90];
    $sweep = [
        ['w' => 0.3, 'precision_make_model' => 0.80, 'coverage' => 0.95],
        ['w' => 0.5, 'precision_make_model' => 0.80, 'coverage' => 0.95],
        ['w' => 0.7, 'precision_make_model' => 0.90, 'coverage' => 0.80],
        ['w' => 1.0, 'precision_make_model' => 0.99, 'coverage' => 0.99],
    ];

    expect($scorer->best($sweep, $baseline)['w'])->toBe(0.5)
        ->and($scorer->best([['w' => 0.5, 'precision_make_model' => 0.709, 'coverage' => 0.95]], $baseline))->toBeNull();
});

it('AC-5 sweeps the weight over fixed vectors with the baseline, the flagged title run and the latency', function (): void {
    hybridEvalScene();
    hybridEvalFakeTitles();
    $service = app(SimilarEvalService::class);
    $photos = array_values(array_filter($service->photos(), fn ($photo): bool => $photo->make === 'Toyota' && $photo->model === 'Corolla'));
    $query = $photos[0];

    $result = $service->evaluateHybrid([$query], 8);

    expect($result['baseline'])->toMatchArray(['w' => 1.0, 'min_score' => 0.60, 'coverage' => 1.0])
        ->and($result['baseline']['precision_make_model'])->toBe(round(3 / 4, 4))
        ->and($result['sweep'])->toHaveCount(11)
        ->and(hybridEvalRow($result, 'sweep', 0.0)['precision_make_model'])->toBe(1.0)
        ->and(hybridEvalRow($result, 'sweep', 0.5)['curve'][0]['text_pair_share'])->toBe(round(2 / 3, 4))
        ->and(hybridEvalRow($result, 'sweep', 0.5)['curve'])->toHaveCount(8)
        ->and($result['best'])->toMatchArray(['w' => 0.9, 'precision_make_model' => 1.0, 'coverage' => 1.0, 'beats_baseline' => true])
        ->and($result['latency']['uncached']['samples'])->toBe(1)
        ->and($result['latency']['budget_ms'])->toBe(80.0)
        ->and($result['latency']['within_budget'])->toBeBool();
});

it('NFR-2 flags the title run as leaking and never chooses it', function (): void {
    hybridEvalScene(captions: false);
    hybridEvalFakeTitles();
    $service = app(SimilarEvalService::class);
    $query = collect($service->photos())->first(fn ($photo): bool => $photo->make === 'Toyota');

    $result = $service->evaluateHybrid([$query], 8);

    expect($result['title'])->toMatchArray(['source' => 'title', 'leaks_ground_truth' => true, 'headline' => false])
        ->and(hybridEvalRow($result, 'title.sweep', 0.0)['precision_make_model'])->toBe(1.0)
        ->and($result['baseline']['precision_make_model'])->toBeLessThan(1.0)
        ->and($result['best'])->toMatchArray(['w' => 1.0, 'min_score' => 0.60, 'beats_baseline' => false])
        ->and($result['sweep'][0]['text_pair_share'])->toBe(0.0);
});

it('AC-5 writes hybrid.json from photos:similar-eval --hybrid and refuses to overwrite it without --force', function (): void {
    hybridEvalScene();
    hybridEvalFakeTitles();

    $this->artisan('photos:similar-eval', ['--hybrid' => true])->assertSuccessful();

    $run = hybridEvalRun();

    expect($run['metadata'])->toMatchArray(['photos' => 5, 'k' => 8, 'label' => HYBRID_EVAL_LABEL])
        ->and($run)->toHaveKeys(['baseline', 'sweep', 'best', 'title', 'latency'])
        ->and($run['title']['leaks_ground_truth'])->toBeTrue()
        ->and(File::exists(hybridEvalRoot().'/photo-similar/2026-11-11/run.json'))->toBeFalse();

    $this->artisan('photos:similar-eval', ['--hybrid' => true])->assertFailed();
    $this->artisan('photos:similar-eval', ['--hybrid' => true, '--force' => true])->assertSuccessful();
});
