<?php

declare(strict_types=1);

use App\Ai\AiProviders;
use App\Ai\AiRunRecorder;
use App\Contracts\ListingPhotoRepositoryInterface;
use App\DTOs\PhotoEmbedding;
use App\DTOs\SimilarEvalQuery;
use App\DTOs\SimilarPhotoMatch;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Services\PhotoEmbeddingService;
use App\Services\SimilarEvalScorer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const SIMILAR_EVAL_LABEL = 'photo-encoder/openai/clip-vit-base-patch32';

function similarEvalRoot(): string
{
    return storage_path('framework/testing/photo-similar-eval');
}

function similarEvalMatch(string $make, string $model, float $similarity = 0.9, int $id = 1): SimilarPhotoMatch
{
    return new SimilarPhotoMatch($id, $id, "2020 {$make} {$model}", $make, $model, "photos/{$id}.jpg", $similarity);
}

/**
 * @return list<float>
 */
function similarEvalVector(float $score): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[0] = $score;
    $vector[1] = sqrt(1 - $score * $score);

    return $vector;
}

function similarEvalPhoto(string $make, string $model, float $score): ListingPhoto
{
    $photo = ListingPhoto::factory()->create(['listing_id' => Listing::factory()->create(['make' => $make, 'model' => $model])->id]);
    app(ListingPhotoRepositoryInterface::class)->storeEmbeddings([new PhotoEmbedding($photo->id, similarEvalVector($score))], SIMILAR_EVAL_LABEL, CarbonImmutable::now());

    return $photo;
}

/**
 * @return array<string, mixed>
 */
function similarEvalRun(): array
{
    return json_decode((string) File::get(similarEvalRoot().'/photo-similar/2026-11-11/run.json'), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    File::ensureDirectoryExists(similarEvalRoot());
    config([
        'ai.docs.eval_results_root' => similarEvalRoot(),
        'ai.features.embeddings-photos.provider' => 'photo-encoder',
        'ai.features.embeddings-photos.model' => null,
        'ai.similar_photos.min_score' => 0.80,
        'ai.similar_photos.near_duplicate_cutoff' => 0.98,
    ]);
    Carbon::setTestNow('2026-11-11 10:00:00');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Carbon::setTestNow();
    File::deleteDirectory(similarEvalRoot());
});

it('AC-8 computes precision, coverage and latency on known answers', function (): void {
    $scorer = new SimilarEvalScorer;

    $report = $scorer->score([
        new SimilarEvalQuery('Audi', 'A4', [similarEvalMatch('Audi', 'A4'), similarEvalMatch('Audi', 'A6'), similarEvalMatch('BMW', 'X5'), similarEvalMatch('Audi', 'A4')]),
        new SimilarEvalQuery('BMW', 'X5', [similarEvalMatch('BMW', 'X5')]),
        new SimilarEvalQuery('Fiat', '500', []),
    ], 3);

    // k = 3 keeps three of the first query's four results: 2 of 3 share the make, 1 of 3 the make and model;
    // the second query adds 1 of 1 on both. Micro average over the 4 results returned.
    expect($report['queries'])->toBe(3)
        ->and($report['answered'])->toBe(2)
        ->and($report['coverage'])->toBe(0.6667)
        ->and($report['returned'])->toBe(4)
        ->and($report['precision_make'])->toBe(0.75)
        ->and($report['precision_make_model'])->toBe(0.5);

    expect($scorer->latency([4000, 1000, 2000, 3000, 10_000]))->toBe(['samples' => 5, 'p50_ms' => 3.0, 'p95_ms' => 10.0, 'mean_ms' => 4.0])
        ->and($scorer->latency([]))->toBe(['samples' => 0, 'p50_ms' => null, 'p95_ms' => null, 'mean_ms' => null])
        ->and($scorer->throughput(32, 4000))->toBe(8.0)
        ->and($scorer->throughput(0, 0))->toBeNull();
});

it('AC-8 reports zero precision and coverage for an empty set of results', function (): void {
    $report = (new SimilarEvalScorer)->score([new SimilarEvalQuery('Fiat', '500', [])], 8);

    expect($report['coverage'])->toBe(0.0)->and($report['precision_make'])->toBe(0.0)->and($report['precision_make_model'])->toBe(0.0);
});

it('AC-8 writes run.json with the metrics, the threshold curve, both latencies and the throughput', function (): void {
    similarEvalPhoto('Audi', 'A4', 1.0);
    similarEvalPhoto('Audi', 'A4', 0.95);
    similarEvalPhoto('Audi', 'A6', 0.90);
    similarEvalPhoto('BMW', 'X5', 0.85);
    $choice = app(AiProviders::class)->configured(PhotoEmbeddingService::FEATURE);
    app(AiRunRecorder::class)->recordRun('completed', 'PhotoEncoder', $choice->provider, $choice->model, null, 2000, null, invocationId: 'embed:'.Str::uuid().':a', inputs: 16);

    $this->artisan('photos:similar-eval')->assertExitCode(0);

    $run = similarEvalRun();

    expect($run['metadata']['label'])->toBe(SIMILAR_EVAL_LABEL)
        ->and($run['metadata']['k'])->toBe(8)
        ->and($run['metadata']['photos'])->toBe(4)
        ->and($run['metrics']['queries'])->toBe(4)
        ->and($run['metrics']['coverage'])->toBeGreaterThan(0.0)
        ->and($run['metrics'])->toHaveKeys(['precision_make', 'precision_make_model', 'answered', 'returned'])
        ->and($run['latency']['uncached']['samples'])->toBe(4)
        ->and($run['latency']['cached']['samples'])->toBe(4)
        ->and($run['latency']['cached'])->toHaveKeys(['p50_ms', 'p95_ms'])
        ->and($run['throughput']['photos_per_second'])->toEqual(8.0)
        ->and(array_column($run['curve'], 'threshold'))->toBe([0.6, 0.65, 0.7, 0.75, 0.8, 0.85, 0.9, 0.95])
        ->and($run['curve'][0])->toHaveKeys(['threshold', 'coverage', 'precision_make', 'precision_make_model']);

});

it('AC-8 takes the throughput from the latest photos:embed run of the current model only', function (): void {
    similarEvalPhoto('Audi', 'A4', 1.0);
    $choice = app(AiProviders::class)->configured(PhotoEmbeddingService::FEATURE);
    $recorder = app(AiRunRecorder::class);
    $older = (string) Str::uuid();
    $latest = (string) Str::uuid();
    $otherModel = (string) Str::uuid();

    $recorder->recordRun('completed', 'PhotoEncoder', $choice->provider, $choice->model, null, 90000, null, invocationId: "embed:{$older}:a", inputs: 100);
    $recorder->recordRun('completed', 'PhotoEncoder', $choice->provider, 'other-model', null, 70000, null, invocationId: "embed:{$otherModel}:a", inputs: 50);
    $recorder->recordRun('completed', 'PhotoEncoder', $choice->provider, $choice->model, null, 2000, null, invocationId: "embed:{$latest}:a", inputs: 16);
    $recorder->recordRun('completed', 'PhotoEncoder', $choice->provider, $choice->model, null, 2000, null, invocationId: "embed:{$latest}:b", inputs: 16);
    $recorder->recordRun('failed', 'PhotoEncoder', $choice->provider, $choice->model, null, 500, null, invocationId: "embed:{$latest}:c", inputs: 16);

    $this->artisan('photos:similar-eval')->assertExitCode(0);

    expect(similarEvalRun()['throughput'])->toEqual(['photos' => 32, 'encoder_ms' => 4000, 'photos_per_second' => 8.0]);
});

it('AC-8 computes the metrics at the configured minimum score on generated vectors', function (): void {
    similarEvalPhoto('Audi', 'A4', 1.0);
    similarEvalPhoto('Audi', 'A4', 0.95);
    similarEvalPhoto('Audi', 'A6', 0.90);
    similarEvalPhoto('BMW', 'X5', 0.60);

    $this->artisan('photos:similar-eval', ['--k' => 2])->assertExitCode(0);

    $metrics = similarEvalRun()['metrics'];

    // Cosines: A4 1.0 to A4 0.95 is 0.95, to A6 0.90 is 0.90, to X5 0.60; A4 0.95 to A6 0.90 is 0.99 (a near duplicate,
    // dropped), to X5 0.82; A6 to X5 is 0.89. Eight results in all: 4 share the make, 2 the make and model.
    expect($metrics)->toEqual([
        'queries' => 4,
        'answered' => 4,
        'coverage' => 1.0,
        'returned' => 8,
        'precision_make' => 0.5,
        'precision_make_model' => 0.25,
    ]);
});

it('AC-8 exits 1 when no photo is embedded with the current label', function (): void {
    ListingPhoto::factory()->create();

    $this->artisan('photos:similar-eval')->assertExitCode(1);

    expect(File::exists(similarEvalRoot().'/photo-similar'))->toBeFalse();
});

it('AC-8 rejects a bad --k and refuses to overwrite a result without --force', function (): void {
    similarEvalPhoto('Audi', 'A4', 1.0);
    similarEvalPhoto('Audi', 'A6', 0.9);

    $this->artisan('photos:similar-eval', ['--k' => '0'])->assertExitCode(2);
    $this->artisan('photos:similar-eval')->assertExitCode(0);
    $this->artisan('photos:similar-eval')->assertExitCode(2);
    $this->artisan('photos:similar-eval', ['--force' => true])->assertExitCode(0);
});
