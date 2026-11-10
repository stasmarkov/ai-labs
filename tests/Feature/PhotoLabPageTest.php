<?php

declare(strict_types=1);

use App\Contracts\PhotoExtractionRepositoryInterface;
use App\Jobs\ExtractPhotoJob;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Models\PhotoExtraction;
use App\Services\PhotoLabService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function labRequest(): array
{
    $request = app(PhotoLabService::class)->request();

    return ['model' => $request->model, 'prompt_version' => $request->promptVersion, 'provider' => $request->provider];
}

it('AC-5 the page shows the empty state when there are no photos', function (): void {
    $this->get('/ai/photos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Photos')
            ->has('photos', 0)
            ->where('pagination.total', 0)
            ->where('selected', null)
            ->has('howItWorks')
            ->where('guideHref', '/ai/guides/photos'));
});

it('AC-5 the page lists the photos with the status of their extraction and selects one', function (): void {
    $listing = Listing::factory()->create(['make' => 'Ford', 'model' => 'Fiesta', 'year' => 2012]);
    $done = ListingPhoto::factory()->for($listing)->create(['path' => 'photos/1/1.jpg']);
    $plain = ListingPhoto::factory()->for($listing)->create();
    PhotoExtraction::factory()->for($done, 'photo')->create([
        ...labRequest(),
        'status' => PhotoExtraction::STATUS_COMPLETED,
        'attempts' => 2,
        'data' => ['caption' => 'A red car.'],
        'invocation_ids' => ['run-a', 'run-b'],
        'latency_ms' => 900,
    ]);
    PhotoExtraction::factory()->for($plain, 'photo')->create([
        ...labRequest(),
        'model' => 'other-model',
        'status' => PhotoExtraction::STATUS_FAILED,
    ]);

    $this->get('/ai/photos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('photos', 2)
            ->where('photos.0.id', $done->id)
            ->where('photos.0.title', '2012 Ford Fiesta')
            ->where('photos.0.url', '/storage/photos/1/1.jpg')
            ->where('photos.0.status', 'completed')
            ->where('photos.1.status', null)
            ->where('pagination.total', 2)
            ->where('selected.id', $done->id)
            ->where('selected.data.caption', 'A red car.')
            ->where('selected.attempts', 2)
            ->where('selected.run_href', '/ai/runs/run-b')
            ->where('extraction.queue', 'ai-vision')
            ->where('extractUrl', fn (string $url) => str_contains($url, '/ai/photos/PHOTO_ID/extract'))
            ->where('statusUrl', fn (string $url) => str_contains($url, '/ai/photos/PHOTO_ID/status')));

    $this->get('/ai/photos?photo='.$plain->id)
        ->assertInertia(fn (Assert $page) => $page->where('selected.id', $plain->id)->where('selected.status', null));

    $this->get('/ai/photos?photo=999999')
        ->assertInertia(fn (Assert $page) => $page->where('selected.id', $done->id));
});

it('AC-5 the grid is paginated', function (): void {
    ListingPhoto::factory()->count(PhotoLabService::PER_PAGE + 1)->create();

    $this->get('/ai/photos?page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('photos', 1)
            ->where('pagination.page', 2)
            ->where('pagination.lastPage', 2)
            ->where('pagination.total', PhotoLabService::PER_PAGE + 1));
});

it('AC-5 posting an extraction queues one job for the photo and answers 202', function (): void {
    Queue::fake();
    $photo = ListingPhoto::factory()->create();

    $this->postJson("/ai/photos/{$photo->id}/extract")
        ->assertStatus(202)
        ->assertJsonPath('id', $photo->id)
        ->assertJsonPath('status', 'queued');

    $row = PhotoExtraction::query()->where('photo_id', $photo->id)->sole();

    expect($row->model)->toBe(labRequest()['model'])
        ->and($row->prompt_version)->toBe(labRequest()['prompt_version']);

    Queue::assertPushedOn(ExtractPhotoJob::QUEUE, ExtractPhotoJob::class, fn (ExtractPhotoJob $job): bool => $job->extractionId === $row->id);
    Queue::assertPushed(ExtractPhotoJob::class, 1);
});

it('AC-5 a photo already queued or running answers 409 with its status and is not queued twice', function (string $status): void {
    Queue::fake();
    $photo = ListingPhoto::factory()->create();
    PhotoExtraction::factory()->for($photo, 'photo')->create([...labRequest(), 'status' => $status]);

    $this->postJson("/ai/photos/{$photo->id}/extract")->assertStatus(409)->assertJsonPath('status', $status)->assertJsonPath('id', $photo->id);

    Queue::assertNothingPushed();
})->with([PhotoExtraction::STATUS_QUEUED, PhotoExtraction::STATUS_RUNNING]);

it('NFR-3 the 31st extraction post within a minute answers 429', function (): void {
    Queue::fake();
    $photo = ListingPhoto::factory()->create();
    PhotoExtraction::factory()->for($photo, 'photo')->create([...labRequest(), 'status' => PhotoExtraction::STATUS_RUNNING]);

    foreach (range(1, 30) as $ignored) {
        $this->postJson("/ai/photos/{$photo->id}/extract")->assertStatus(409);
    }

    $this->postJson("/ai/photos/{$photo->id}/extract")->assertStatus(429);
});

it('AC-5 a finished extraction is reset and queued again', function (): void {
    Queue::fake();
    $photo = ListingPhoto::factory()->create();
    PhotoExtraction::factory()->for($photo, 'photo')->create([...labRequest(), 'status' => PhotoExtraction::STATUS_FAILED, 'attempts' => 3, 'errors' => ['caption' => ['x']]]);

    $this->postJson("/ai/photos/{$photo->id}/extract")->assertStatus(202)->assertJsonPath('status', 'queued')->assertJsonPath('errors', null);

    Queue::assertPushed(ExtractPhotoJob::class, 1);
    expect(PhotoExtraction::query()->count())->toBe(1);
});

it('AC-5 the status endpoint reports the extraction of a photo', function (): void {
    $photo = ListingPhoto::factory()->create();

    $this->getJson("/ai/photos/{$photo->id}/status")->assertOk()->assertJsonPath('status', null)->assertJsonPath('attempts', 0);

    PhotoExtraction::factory()->for($photo, 'photo')->create([...labRequest(), 'status' => PhotoExtraction::STATUS_FAILED, 'attempts' => 3, 'errors' => ['caption' => ['too long']]]);

    $this->getJson("/ai/photos/{$photo->id}/status")
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('errors.caption.0', 'too long');
});

it('NFR-3 the lab endpoints take a photo id only and answer 404 for an unknown or malformed one', function (): void {
    Queue::fake();

    $this->postJson('/ai/photos/999999/extract')->assertNotFound();
    $this->getJson('/ai/photos/999999/status')->assertNotFound();
    $this->postJson('/ai/photos/photos%2F1%2F1.jpg/extract')->assertNotFound();
    $this->getJson('/ai/photos/..%2F..%2F.env/status')->assertNotFound();

    Queue::assertNothingPushed();
});

it('NFR-3 a path or model sent in the request is ignored; the queued photo path comes from the table', function (): void {
    Queue::fake();
    $photo = ListingPhoto::factory()->create(['path' => 'photos/7/7.jpg']);

    $this->postJson("/ai/photos/{$photo->id}/extract", ['path' => '../../.env', 'model' => 'evil', 'photo_id' => 999])->assertStatus(202);

    $row = PhotoExtraction::query()->sole();

    expect($row->photo_id)->toBe($photo->id)
        ->and($row->model)->toBe(labRequest()['model'])
        ->and(app(PhotoExtractionRepositoryInterface::class)->findTarget($row->id)?->photoPath)->toBe('photos/7/7.jpg');
});

it('AC-5 photos is a ready capability', function (): void {
    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);

    $this->get('/ai')->assertInertia(fn (Assert $page) => $page
        ->where('capabilities.5.key', 'photos')
        ->where('capabilities.5.status', 'ready'));
});

it('AC-5 lists the photo results of other models next to the effective one', function (): void {
    $current = PhotoExtraction::factory()->create(['status' => 'completed', 'data' => ['caption' => 'From gemma.']]);
    PhotoExtraction::factory()->create([
        'photo_id' => $current->photo_id,
        'model' => 'qwen3.5:9b',
        'status' => 'completed',
        'data' => ['caption' => 'From qwen.'],
        'invocation_ids' => ['run-q'],
    ]);

    $this->get('/ai/photos?photo='.$current->photo_id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.data.caption', 'From gemma.')
            ->has('selected.others', 1)
            ->where('selected.others.0.model', 'qwen3.5:9b')
            ->where('selected.others.0.data.caption', 'From qwen.')
            ->where('selected.others.0.run_href', '/ai/runs/run-q'));
});
