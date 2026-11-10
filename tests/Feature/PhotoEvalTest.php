<?php

declare(strict_types=1);

use App\Ai\PhotoVisionAgent;
use App\DTOs\PhotoEvalOutcome;
use App\Models\AiRun;
use App\Models\ListingPhoto;
use App\Models\PhotoExtraction;
use App\Services\PhotoBodyMapper;
use App\Services\PhotoEvalScorer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

function photoEvalRoot(): string
{
    return storage_path('framework/testing/photo-eval');
}

/**
 * @param  array<string, mixed>  $change
 * @return array<string, mixed>
 */
function evalAnswer(array $change = []): array
{
    return [
        'caption' => 'A car on a street.',
        'tags' => ['street'],
        'dominant_colours' => ['red'],
        'body_type' => 'saloon',
        'visible_damage' => 'none',
        'people_count' => 0,
        ...$change,
    ];
}

/**
 * @param  list<string>  $classes  source classes, in sha256 order
 */
function evalPhotos(array $classes): void
{
    foreach ($classes as $index => $class) {
        ListingPhoto::factory()->create(['source_class' => $class, 'sha256' => str_repeat((string) $index, 64)]);
    }
}

/**
 * @return array<string, mixed>
 */
function photoEvalRun(): array
{
    return json_decode((string) File::get(photoEvalRoot().'/photo-vision/2026-11-11/run.json'), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    File::ensureDirectoryExists(photoEvalRoot());
    config(['ai.docs.eval_results_root' => photoEvalRoot()]);
    Carbon::setTestNow('2026-11-11 10:00:00');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Carbon::setTestNow();
    File::deleteDirectory(photoEvalRoot());
});

it('AC-6 computes the metrics on known answers', function (): void {
    $outcome = fn (int $id, int $attempts, ?array $data, int $latency, array $attemptLatencies, ?string $expected): PhotoEvalOutcome => new PhotoEvalOutcome($id, $attempts, $data, $latency, $attemptLatencies, $expected);

    $report = (new PhotoEvalScorer)->score('gemma4:12b', [
        $outcome(1, 1, evalAnswer(['body_type' => 'saloon']), 100, [100], 'saloon'),
        $outcome(2, 2, evalAnswer(['body_type' => 'SUV']), 400, [150, 250], 'suv'),
        $outcome(3, 1, evalAnswer(['body_type' => 'unknown']), 200, [200], 'estate'),
        $outcome(4, 3, null, 900, [300, 300, 300], 'hatchback'),
        $outcome(5, 1, evalAnswer(['body_type' => 'coupe']), 300, [300], null),
    ]);

    expect($report['photos'])->toBe(5)
        ->and($report['valid_first_attempt'])->toBe(['count' => 3, 'rate' => 0.6])
        ->and($report['valid_after_retries'])->toBe(['count' => 4, 'rate' => 0.8])
        ->and($report['mean_attempts'])->toBe(1.6)
        ->and($report['latency_ms'])->toBe(['p50' => 300, 'p95' => 900, 'mean_per_attempt' => 238])
        ->and($report['body_type_agreement'])->toBe(['agreed' => 2, 'compared' => 4, 'rate' => 0.5])
        ->and($report['colour_presence'])->toBe(['count' => 4, 'rate' => 0.8]);
});

it('AC-6 counts a valid answer with no colour as missing a colour and handles an empty sample', function (): void {
    $scorer = new PhotoEvalScorer;

    $report = $scorer->score('m', [new PhotoEvalOutcome(1, 1, evalAnswer(['dominant_colours' => []]), 10, [10], null)]);
    $empty = $scorer->score('m', []);

    expect($report['colour_presence']['rate'])->toBe(0.0)
        ->and($report['body_type_agreement'])->toBe(['agreed' => 0, 'compared' => 0, 'rate' => 0.0])
        ->and($empty['valid_first_attempt']['rate'])->toBe(0.0)
        ->and($empty['latency_ms'])->toBe(['p50' => null, 'p95' => null, 'mean_per_attempt' => null]);
});

it('AC-6 maps the Stanford body words to catalog body types', function (string $class, ?string $body): void {
    expect(app(PhotoBodyMapper::class)->forClass($class))->toBe($body);
})->with([
    'sedan' => ['Ford Focus Sedan 2012', 'saloon'],
    'hatchback' => ['Volkswagen Golf Hatchback 2012', 'hatchback'],
    'wagon' => ['Volkswagen Passat Wagon 2012', 'estate'],
    'suv' => ['Ford Kuga SUV 2012', 'SUV'],
    'coupe' => ['Ford Mustang Coupe 2012', 'coupe'],
    'cab' => ['Ford Puma Extended Cab 2012', 'pickup'],
    'cargo van' => ['Ford Puma Cargo Van 2012', 'van'],
    'minivan' => ['Ford Puma Minivan 2012', 'MPV'],
    'no mapping' => ['Ford Puma Fastback 2012', null],
    'no body words' => ['Ford Puma 2012', null],
    'not in the catalog' => ['Bugatti Veyron Coupe 2012', null],
]);

it('AC-6 runs the sample in sha256 order and writes run.json', function (): void {
    evalPhotos(['Ford Focus Sedan 2012', 'Ford Kuga SUV 2012', 'Ford Mustang Coupe 2012', 'Ford Puma Fastback 2012']);
    PhotoVisionAgent::fake([
        evalAnswer(['body_type' => 'limousine']),
        evalAnswer(['body_type' => 'saloon']),
        evalAnswer(['body_type' => 'hatchback']),
        fn (): array => [],
        fn (): array => [],
        fn (): array => [],
    ]);

    $this->artisan('photos:eval', ['--photos' => 3, '--models' => 'gemma4:12b'])->assertExitCode(0);

    $run = photoEvalRun();
    $model = $run['models'][0];

    expect($run['metadata'])->toMatchArray(['photos' => 3, 'models' => ['gemma4:12b'], 'prompt_version' => 'photo-vision@v1'])
        ->and(array_column($run['results'], 'photo_id'))->toBe(ListingPhoto::query()->orderBy('sha256')->limit(3)->pluck('id')->all())
        ->and($model['valid_first_attempt'])->toBe(['count' => 1, 'rate' => 0.3333])
        ->and($model['valid_after_retries'])->toBe(['count' => 2, 'rate' => 0.6667])
        ->and($model['mean_attempts'])->toEqual(2.0)
        ->and($model['body_type_agreement'])->toBe(['agreed' => 1, 'compared' => 3, 'rate' => 0.3333])
        ->and($run['results'][2])->toMatchArray(['valid' => false, 'attempts' => 3, 'body_type' => null]);

    PhotoVisionAgent::assertPromptedTimes(6);
});

it('AC-6 logs every attempt as an eval run and stores no extraction', function (): void {
    evalPhotos(['Ford Focus Sedan 2012', 'Ford Kuga SUV 2012']);
    PhotoVisionAgent::fake([evalAnswer(['body_type' => 'limousine']), evalAnswer(), evalAnswer()]);

    $this->artisan('photos:eval', ['--photos' => 2, '--models' => 'gemma4:12b'])->assertExitCode(0);

    expect(AiRun::count())->toBe(3)
        ->and(AiRun::pluck('origin')->unique()->all())->toBe(['eval'])
        ->and(AiRun::pluck('prompt_version')->unique()->all())->toBe(['photo-vision@v1'])
        ->and(PhotoExtraction::count())->toBe(0);
});

it('AC-6 refuses a second run on the same day without --force', function (): void {
    evalPhotos(['Ford Focus Sedan 2012']);
    PhotoVisionAgent::fake([evalAnswer(), evalAnswer()]);

    $this->artisan('photos:eval', ['--photos' => 1, '--models' => 'gemma4:12b'])->assertExitCode(0);
    $first = File::get(photoEvalRoot().'/photo-vision/2026-11-11/run.json');

    $this->artisan('photos:eval', ['--photos' => 1, '--models' => 'gemma4:12b'])->assertExitCode(2);
    expect(File::get(photoEvalRoot().'/photo-vision/2026-11-11/run.json'))->toBe($first);

    $this->artisan('photos:eval', ['--photos' => 1, '--models' => 'gemma4:12b', '--force' => true])->assertExitCode(0);
});

it('AC-6 fails with the count when fewer photos are imported than asked', function (): void {
    evalPhotos(['Ford Focus Sedan 2012', 'Ford Kuga SUV 2012']);
    PhotoVisionAgent::fake([]);

    $this->artisan('photos:eval', ['--photos' => 5, '--models' => 'gemma4:12b'])
        ->expectsOutputToContain('Only 2 photos are imported; 5 are needed.')
        ->assertExitCode(1);

    PhotoVisionAgent::assertNeverPrompted();
    expect(File::exists(photoEvalRoot().'/photo-vision'))->toBeFalse();
});

it('AC-6 compares each model of the provider and refuses one without vision', function (): void {
    evalPhotos(['Ford Focus Sedan 2012']);
    Http::fake([
        '*/api/tags' => Http::response(['models' => [['name' => 'gemma4:12b'], ['name' => 'qwen3.5:9b'], ['name' => 'llama3:8b']]]),
        '*/api/show' => fn ($request) => Http::response(['capabilities' => $request['model'] === 'llama3:8b' ? ['completion'] : ['completion', 'vision']]),
    ]);
    PhotoVisionAgent::fake([evalAnswer(), evalAnswer(['body_type' => 'hatchback'])]);

    $this->artisan('photos:eval', ['--photos' => 1, '--models' => 'llama3:8b'])
        ->expectsOutputToContain('llama3:8b is not a vision model on this Ollama.')
        ->assertExitCode(1);

    $this->artisan('photos:eval', ['--photos' => 1])->assertExitCode(0);

    expect(array_column(photoEvalRun()['models'], 'model'))->toBe(['gemma4:12b', 'qwen3.5:9b'])
        ->and(array_column(array_column(photoEvalRun()['models'], 'body_type_agreement'), 'agreed'))->toBe([1, 0]);
});

it('AC-6 rejects a bad --photos value', function (): void {
    $this->artisan('photos:eval', ['--photos' => 0])->assertExitCode(2);
});
