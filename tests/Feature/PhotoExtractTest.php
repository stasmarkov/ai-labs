<?php

declare(strict_types=1);

use App\Ai\PhotoVisionAgent;
use App\Contracts\PhotoExtractionRepositoryInterface;
use App\DTOs\PhotoExtractionRequest;
use App\Jobs\ExtractPhotoJob;
use App\Models\AiRun;
use App\Models\ListingPhoto;
use App\Models\PhotoExtraction;
use App\Services\PhotoExtractionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * @param  array<string, mixed>  $change
 * @return array<string, mixed>
 */
function extraction(array $change = []): array
{
    return [
        'caption' => 'Red hatchback parked on a street.',
        'tags' => ['alloy wheels', 'street'],
        'dominant_colours' => ['red'],
        'body_type' => 'hatchback',
        'visible_damage' => 'none',
        'people_count' => 0,
        ...$change,
    ];
}

function extractPhoto(?ListingPhoto $photo = null): PhotoExtraction
{
    $photo ??= ListingPhoto::factory()->create();
    $row = PhotoExtraction::factory()->for($photo, 'photo')->create();

    (new ExtractPhotoJob($row->id))->handle(app(PhotoExtractionService::class));

    return $row->refresh();
}

it('AC-3 stores a valid answer on the first attempt', function (): void {
    PhotoVisionAgent::fake([extraction()]);

    $row = extractPhoto();

    expect($row->status)->toBe('completed')
        ->and($row->attempts)->toBe(1)
        ->and($row->data)->toEqualCanonicalizing(extraction())
        ->and($row->errors)->toBeNull()
        ->and($row->invocation_ids)->toHaveCount(1)
        ->and($row->latency_ms)->toBeInt();

    PhotoVisionAgent::assertPromptedTimes(1);
    expect(AiRun::count())->toBe(1);
});

it('AC-4 retries invalid answers and stores the third attempt when it is valid', function (): void {
    PhotoVisionAgent::fake([
        extraction(['body_type' => 'limousine']),
        extraction(['people_count' => 99]),
        extraction(['body_type' => 'SUV']),
    ]);

    $row = extractPhoto();

    expect($row->status)->toBe('completed')
        ->and($row->attempts)->toBe(3)
        ->and($row->data['body_type'])->toBe('SUV')
        ->and($row->errors)->toBeNull();

    PhotoVisionAgent::assertPromptedTimes(3);
});

it('AC-4 marks the photo failed with the last errors after three invalid answers', function (): void {
    PhotoVisionAgent::fake([
        extraction(['body_type' => 'limousine']),
        extraction(['people_count' => 99]),
        extraction(['caption' => '']),
        extraction(),
    ]);

    $row = extractPhoto();

    expect($row->status)->toBe('failed')
        ->and($row->attempts)->toBe(3)
        ->and($row->data)->toBeNull()
        ->and(array_keys($row->errors))->toBe(['caption'])
        ->and($row->invocation_ids)->toHaveCount(3);

    PhotoVisionAgent::assertPromptedTimes(3);
});

it('AC-4 writes one ai_runs row per attempt and flags the invalid ones', function (): void {
    PhotoVisionAgent::fake([extraction(['visible_damage' => 'total']), extraction(['tags' => ['Wheels']]), extraction()]);

    $row = extractPhoto();
    $runs = AiRun::query()->whereIn('invocation_id', $row->invocation_ids)->orderBy('id')->get();

    expect($runs)->toHaveCount(3)
        ->and($runs->pluck('validation_failed')->all())->toBe([true, true, false])
        ->and($runs->pluck('agent')->unique()->all())->toBe(['PhotoVisionAgent'])
        ->and($runs->pluck('prompt_version')->unique()->all())->toBe(['photo-vision@v1'])
        ->and($runs->pluck('status')->unique()->all())->toBe(['completed'])
        ->and($runs->pluck('model')->unique()->all())->toBe(['gemma4:12b']);
});

it('AC-4 treats an empty answer as an invalid attempt and retries', function (): void {
    PhotoVisionAgent::fake([fn (): array => [], extraction()]);

    $row = extractPhoto();

    expect($row->status)->toBe('completed')
        ->and($row->attempts)->toBe(2);
    expect(AiRun::query()->orderBy('id')->pluck('status')->all())->toBe(['failed', 'completed']);
});

it('AC-4 records a transport error as one failed attempt with no retry', function (): void {
    PhotoVisionAgent::fake([fn () => throw new ConnectionException('cURL error 7: Connection refused')]);

    $row = extractPhoto();

    expect($row->status)->toBe('failed')
        ->and($row->attempts)->toBe(1)
        ->and($row->errors['transport'][0])->toBe(ConnectionException::class)
        ->and($row->errors['transport'][1])->toContain('Connection refused');

    PhotoVisionAgent::assertPromptedTimes(1);
    expect(AiRun::query()->pluck('status')->all())->toBe(['failed']);
});

it('NFR-2 queues each job on ai-vision and does not retry in the queue', function (): void {
    Queue::fake();
    ListingPhoto::factory()->count(2)->create();

    $this->artisan('photos:extract')->assertSuccessful();

    Queue::assertPushedOn('ai-vision', ExtractPhotoJob::class);
    Queue::assertPushed(ExtractPhotoJob::class, 2);
    expect((new ExtractPhotoJob(1))->queue)->toBe('ai-vision')
        ->and((new ExtractPhotoJob(1))->tries)->toBe(1);
    expect(PhotoExtraction::query()->pluck('status')->unique()->all())->toBe(['queued']);
});

it('AC-4 marks the row failed when the job dies', function (): void {
    $row = PhotoExtraction::factory()->create(['status' => 'running']);

    (new ExtractPhotoJob($row->id))->failed(new RuntimeException('worker killed'));

    expect($row->refresh()->status)->toBe('failed')
        ->and($row->errors['transport'][1])->toBe('worker killed');
});

it('AC-3 extracts every pending photo through the command and skips finished ones', function (): void {
    PhotoVisionAgent::fake([extraction(), extraction()]);
    $photos = ListingPhoto::factory()->count(3)->create();
    PhotoExtraction::factory()->for($photos[0], 'photo')->create(['status' => 'completed', 'attempts' => 1]);

    $this->artisan('photos:extract')
        ->expectsOutputToContain('Queued 2 photos on ai-vision')
        ->expectsOutputToContain('Completed 2, failed 0')
        ->assertSuccessful();

    expect(PhotoExtraction::query()->where('status', 'completed')->count())->toBe(3);
});

it('AC-3 honours --limit and rejects a bad one', function (): void {
    Queue::fake();
    ListingPhoto::factory()->count(3)->create();

    $this->artisan('photos:extract', ['--limit' => 'x'])->assertExitCode(2);
    $this->artisan('photos:extract', ['--limit' => 2])->assertSuccessful();

    Queue::assertPushed(ExtractPhotoJob::class, 2);
    expect(PhotoExtraction::count())->toBe(2);
});

it('AC-3 --fresh redoes only the rows of the model and prompt version', function (): void {
    Queue::fake();
    $photo = ListingPhoto::factory()->create();
    $same = PhotoExtraction::factory()->for($photo, 'photo')->create(['status' => 'completed']);
    $other = PhotoExtraction::factory()->for($photo, 'photo')->create(['model' => 'qwen3.5:9b', 'status' => 'completed']);

    $this->artisan('photos:extract')->assertSuccessful();
    Queue::assertNothingPushed();

    $this->artisan('photos:extract', ['--fresh' => true])->assertSuccessful();

    Queue::assertPushed(ExtractPhotoJob::class, 1);
    expect($same->fresh())->toBeNull()
        ->and($other->fresh()->status)->toBe('completed')
        ->and(PhotoExtraction::where('model', 'gemma4:12b')->value('status'))->toBe('queued');
});

it('AC-3 --model queues a vision model installed on Ollama', function (): void {
    Queue::fake();
    Http::fake([
        '*/api/tags' => Http::response(['models' => [['name' => 'qwen3.5:9b', 'model' => 'qwen3.5:9b']]]),
        '*/api/show' => Http::response(['capabilities' => ['completion', 'vision']]),
    ]);
    ListingPhoto::factory()->create();

    $this->artisan('photos:extract', ['--model' => 'qwen3.5:9b'])->assertSuccessful();

    Queue::assertPushed(ExtractPhotoJob::class, 1);
    expect(PhotoExtraction::value('model'))->toBe('qwen3.5:9b');
});

it('AC-3 --model refuses a model of another provider or one without vision', function (string $model, array $tags, array $capabilities): void {
    Queue::fake();
    Http::fake([
        '*/api/tags' => Http::response(['models' => $tags]),
        '*/api/show' => Http::response(['capabilities' => $capabilities]),
    ]);
    ListingPhoto::factory()->create();

    $this->artisan('photos:extract', ['--model' => $model])
        ->expectsOutputToContain("{$model} is not a vision model on this Ollama.")
        ->assertFailed();

    Queue::assertNothingPushed();
    expect(PhotoExtraction::count())->toBe(0);
})->with([
    'a cloud model id' => ['claude-sonnet-4-5', [['name' => 'gemma4:12b']], ['vision']],
    'a text-only model' => ['llama3.2:3b', [['name' => 'llama3.2:3b']], ['completion']],
]);

it('a job queued for one provider fails with a re-queue message when photo-vision now runs on another', function (): void {
    $row = PhotoExtraction::factory()->create(['status' => 'queued', 'provider' => 'some-old-provider']);
    PhotoVisionAgent::fake();

    (new ExtractPhotoJob($row->id))->handle(app(PhotoExtractionService::class));

    expect($row->refresh()->status)->toBe('failed')
        ->and($row->errors['transport'][1])->toContain('queue it again');
    PhotoVisionAgent::assertNeverPrompted();
});

it('queues a photo only once when two requests race for the same row', function (): void {
    $row = PhotoExtraction::factory()->create(['status' => 'completed']);
    $request = new PhotoExtractionRequest($row->provider, $row->model, $row->prompt_version);
    $repository = app(PhotoExtractionRepositoryInterface::class);

    expect($repository->queue($row->photo_id, $request))->toBe($row->id)
        ->and($repository->queue($row->photo_id, $request))->toBeNull();
});
