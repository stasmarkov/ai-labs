<?php

declare(strict_types=1);

use App\Jobs\EmbedPhotosBatchJob;
use App\Models\AiRun;
use App\Models\ListingPhoto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Storage::fake('public');
    config([
        'ai.features.embeddings-photos.provider' => 'photo-encoder',
        'ai.features.embeddings-photos.model' => null,
        'ai.providers.photo-encoder.url' => 'http://encoder.test:7997',
    ]);
});

/**
 * @return Collection<int, ListingPhoto>
 */
function photosWithFiles(int $count): Collection
{
    $photos = ListingPhoto::factory()->count($count)->create();

    foreach ($photos as $photo) {
        Storage::disk('public')->put($photo->path, "bytes-of-{$photo->id}");
    }

    return $photos;
}

function fakeEncoder(int $dimensions = 512): void
{
    Http::fake(['encoder.test:7997/embeddings' => function (Request $request) use ($dimensions) {
        $data = array_map(
            fn (int $index): array => ['index' => $index, 'embedding' => array_map(fn (int $i): float => ($i % 7) + 1.0, range(1, $dimensions))],
            array_keys($request['input']),
        );

        return Http::response(['data' => $data]);
    }]);
}

function embeddedPhotoCount(): int
{
    return ListingPhoto::query()->whereNotNull('embedding')->count();
}

it('AC-2 stores a normalised 512-d vector, the model label and embedded_at for every photo', function (): void {
    $photos = photosWithFiles(3);
    fakeEncoder();

    $this->artisan('photos:embed')->assertSuccessful();

    expect(embeddedPhotoCount())->toBe(3);

    $photo = $photos->first()->refresh();
    expect($photo->embedding_model)->toBe('photo-encoder/openai/clip-vit-base-patch32')
        ->and($photo->embedded_at)->not->toBeNull()
        ->and((int) DB::scalar('SELECT vector_dims(embedding) FROM listing_photos WHERE id = ?', [$photo->id]))->toBe(512)
        ->and(round((float) DB::scalar('SELECT vector_norm(embedding) FROM listing_photos WHERE id = ?', [$photo->id]), 4))->toBe(1.0);
});

it('AC-2 adds the columns without touching the existing photo rows or the HNSW cosine index', function (): void {
    expect(Schema::hasColumns('listing_photos', ['embedding', 'embedding_model', 'embedded_at']))->toBeTrue();

    $index = DB::scalar("SELECT indexdef FROM pg_indexes WHERE tablename = 'listing_photos' AND indexname = 'listing_photos_embedding_hnsw_idx'");

    expect($index)->toContain('hnsw')->toContain('vector_cosine_ops');

    $photo = ListingPhoto::factory()->create();
    expect($photo->refresh()->embedding_model)->toBeNull()->and($photo->embedded_at)->toBeNull();
});

it('AC-2 sends 20 photos as batches of 16 and 4 with one ai_runs row per batch', function (): void {
    photosWithFiles(20);
    fakeEncoder();

    $this->artisan('photos:embed')
        ->expectsOutputToContain('Queued 2 jobs for 20 photos')
        ->assertSuccessful();

    $runs = AiRun::query()->where('agent', 'PhotoEncoder')->orderBy('id')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('inputs')->all())->toBe([16, 4])
        ->and($runs->pluck('status')->unique()->all())->toBe(['completed'])
        ->and($runs->first()->provider)->toBe('photo-encoder')
        ->and($runs->first()->model)->toBe('openai/clip-vit-base-patch32')
        ->and(embeddedPhotoCount())->toBe(20);

    Http::assertSentCount(3);
});

it('NFR-2 queues one job per batch on the ai-vision queue and embeds nothing in the command', function (): void {
    photosWithFiles(20);
    fakeEncoder();
    Queue::fake();

    $this->artisan('photos:embed')->assertSuccessful();

    Queue::assertPushedOn('ai-vision', EmbedPhotosBatchJob::class);
    Queue::assertPushed(EmbedPhotosBatchJob::class, 2);
    expect(embeddedPhotoCount())->toBe(0);
});

it('AC-2 skips photos already embedded with the current label and re-embeds with --fresh', function (): void {
    photosWithFiles(3);
    fakeEncoder();

    $this->artisan('photos:embed')->assertSuccessful();
    Http::assertSentCount(2);

    $this->artisan('photos:embed')
        ->expectsOutputToContain('Queued 0 jobs for 0 photos')
        ->assertSuccessful();
    Http::assertSentCount(3);

    $this->artisan('photos:embed', ['--fresh' => true, '--wait' => true])->assertSuccessful();

    expect(embeddedPhotoCount())->toBe(3);
    Http::assertSentCount(5);
});

it('AC-2 re-embeds a photo whose vector was made with another model label', function (): void {
    $photos = photosWithFiles(2);
    fakeEncoder();
    $this->artisan('photos:embed')->assertSuccessful();

    ListingPhoto::query()->whereKey($photos->first()->id)->update(['embedding_model' => 'photo-encoder/other-model']);

    $this->artisan('photos:embed')->expectsOutputToContain('Queued 1 jobs for 1 photos')->assertSuccessful();

    expect($photos->first()->refresh()->embedding_model)->toBe('photo-encoder/openai/clip-vit-base-patch32');
});

it('AC-2 --limit embeds only the first photos by id', function (): void {
    $photos = photosWithFiles(4);
    fakeEncoder();

    $this->artisan('photos:embed', ['--limit' => 2])->assertSuccessful();

    expect(ListingPhoto::query()->whereNotNull('embedding')->orderBy('id')->pluck('id')->all())
        ->toBe($photos->sortBy('id')->take(2)->pluck('id')->values()->all());
});

it('AC-2 rejects a --limit that is not a positive whole number', function (): void {
    $this->artisan('photos:embed', ['--limit' => '0'])->assertExitCode(2);
});

it('AC-2 exits 1 before any batch when the encoder is unreachable', function (): void {
    photosWithFiles(2);
    Http::fake(['encoder.test:7997/*' => fn () => throw new ConnectionException('refused')]);
    Queue::fake();

    $this->artisan('photos:embed')->assertExitCode(1);

    Queue::assertNothingPushed();
    expect(embeddedPhotoCount())->toBe(0)->and(AiRun::query()->where('agent', 'PhotoEncoder')->count())->toBe(0);
});

it('AC-2 exits 1 before any batch when the encoder answers with the wrong dimensions', function (): void {
    photosWithFiles(2);
    fakeEncoder(8);
    Queue::fake();

    $this->artisan('photos:embed')->assertExitCode(1);

    Queue::assertNothingPushed();
});

it('AC-2 exits 1 without calling the encoder when the model is configured with other than 512 dimensions', function (): void {
    photosWithFiles(2);
    config(['ai.providers.photo-encoder.models.image_embeddings.dimensions' => 768]);
    Http::fake();
    Queue::fake();

    $this->artisan('photos:embed')->assertExitCode(1);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('AC-2 skips a photo whose file is missing and counts it', function (): void {
    $photos = photosWithFiles(3);
    fakeEncoder();
    Storage::disk('public')->delete($photos->first()->path);

    $this->artisan('photos:embed', ['--wait' => true])
        ->expectsOutputToContain('1 files missing')
        ->assertSuccessful();

    expect(embeddedPhotoCount())->toBe(2)->and($photos->first()->refresh()->embedding)->toBeNull();
});

it('AC-2 a failing batch writes one failed ai_runs row and the other batches continue', function (): void {
    photosWithFiles(20);
    $probed = false;
    Http::fake(['encoder.test:7997/embeddings' => function (Request $request) use (&$probed) {
        $count = count($request['input']);

        if ($probed && $count === 16) {
            return Http::response([], 500);
        }

        $probed = true;

        return Http::response(['data' => array_map(
            fn (int $index): array => ['index' => $index, 'embedding' => array_fill(0, 512, 0.5)],
            range(0, $count - 1),
        )]);
    }]);

    $this->artisan('photos:embed', ['--wait' => true])
        ->expectsOutputToContain('16 photos in failed batches')
        ->assertExitCode(1);

    $runs = AiRun::query()->where('agent', 'PhotoEncoder')->orderBy('id')->get();

    expect($runs->pluck('status')->sort()->values()->all())->toBe(['completed', 'failed'])
        ->and($runs->firstWhere('status', 'failed')->inputs)->toBe(16)
        ->and(embeddedPhotoCount())->toBe(4);
});

it('AC-2 an unexpected error in a batch still settles the run, so --wait reports it instead of waiting 30 minutes', function (): void {
    photosWithFiles(3);
    $probed = false;
    Http::fake(['encoder.test:7997/embeddings' => function (Request $request) use (&$probed) {
        if ($probed) {
            throw new RuntimeException('something unexpected');
        }

        $probed = true;

        return Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 512, 0.5)]]]);
    }]);

    $this->artisan('photos:embed', ['--wait' => true])
        ->expectsOutputToContain('3 photos in failed batches')
        ->assertExitCode(1);

    expect(AiRun::query()->where('agent', 'PhotoEncoder')->where('status', 'failed')->count())->toBe(1);
});

it('AC-2 --wait prints the summary of the run', function (): void {
    photosWithFiles(3);
    fakeEncoder();

    $this->artisan('photos:embed', ['--wait' => true])
        ->expectsOutputToContain('Embedded 3 photos')
        ->assertSuccessful();
});
