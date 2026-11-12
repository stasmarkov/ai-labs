<?php

declare(strict_types=1);

use App\Jobs\EmbedPhotoTextsBatchJob;
use App\Models\AiRun;
use App\Models\ListingPhoto;
use App\Models\PhotoExtraction;
use App\Services\SimilarPhotosService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Exception\InvalidOptionException;

const CAPTION_LABEL = 'photo-encoder/openai/clip-vit-base-patch32';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Storage::fake('public');
    config([
        'ai.features.embeddings-photos.provider' => 'photo-encoder',
        'ai.features.embeddings-photos.model' => null,
        'ai.providers.photo-encoder.url' => 'http://encoder.test:7997',
    ]);
});

function photoWithCaption(?string $caption, array $extraction = []): ListingPhoto
{
    $photo = ListingPhoto::factory()->create();

    if ($caption !== null) {
        PhotoExtraction::factory()->for($photo, 'photo')->create([
            'status' => PhotoExtraction::STATUS_COMPLETED,
            'data' => ['caption' => $caption],
            ...$extraction,
        ]);
    }

    return $photo;
}

function fakeCaptionEncoder(): void
{
    Http::fake(['encoder.test:7997/embeddings' => function (Request $request) {
        $data = array_map(
            fn (int $index): array => ['index' => $index, 'embedding' => array_map(fn (int $i): float => ($i % 5) + 1.0, range(1, 512))],
            array_keys($request['input']),
        );

        return Http::response(['data' => $data]);
    }]);
}

/**
 * @return list<string>
 */
function sentCaptionTexts(): array
{
    $texts = [];

    foreach (Http::recorded() as [$request]) {
        if (($request['modality'] ?? null) === 'text') {
            array_push($texts, ...$request['input']);
        }
    }

    return $texts;
}

it('AC-1 adds the text columns without touching the image vector columns', function (): void {
    expect(Schema::hasColumns('listing_photos', ['embedding', 'text_embedding', 'text_embedding_model', 'text_source', 'text_hash', 'text_embedded_at']))->toBeTrue();

    $photo = ListingPhoto::factory()->create();
    expect($photo->refresh()->text_embedding)->toBeNull()->and($photo->text_hash)->toBeNull();
});

it('AC-1 stores a 512-d caption vector, label, source and hash for a photo with a caption', function (): void {
    $photo = photoWithCaption('Red hatchback parked on a street');
    fakeCaptionEncoder();

    $this->artisan('photos:embed', ['--text' => true, '--wait' => true])->assertSuccessful();

    $photo->refresh();
    expect($photo->text_embedding_model)->toBe(CAPTION_LABEL)
        ->and($photo->text_source)->toBe('caption')
        ->and($photo->text_hash)->toBe(hash('sha256', 'a photo of Red hatchback parked on a street'))
        ->and($photo->text_embedded_at)->not->toBeNull()
        ->and((int) DB::scalar('SELECT vector_dims(text_embedding) FROM listing_photos WHERE id = ?', [$photo->id]))->toBe(512)
        ->and(round((float) DB::scalar('SELECT vector_norm(text_embedding) FROM listing_photos WHERE id = ?', [$photo->id]), 4))->toBe(1.0)
        ->and($photo->embedding_model)->toBeNull();
});

it('AC-1 encodes the caption of the effective model and prompt version wrapped by the template', function (): void {
    $right = photoWithCaption('Blue coupe');
    PhotoExtraction::factory()->for($right, 'photo')->create(['model' => 'other-model', 'status' => 'completed', 'data' => ['caption' => 'wrong model']]);
    PhotoExtraction::factory()->for($right, 'photo')->create(['prompt_version' => 'photo-vision@v0', 'status' => 'completed', 'data' => ['caption' => 'wrong prompt']]);
    photoWithCaption('Green van', ['status' => PhotoExtraction::STATUS_FAILED]);
    fakeCaptionEncoder();

    $this->artisan('photos:embed', ['--text' => true])->assertSuccessful();

    expect(sentCaptionTexts())->toBe(['a photo of Blue coupe']);
    expect($right->refresh()->text_hash)->toBe(hash('sha256', 'a photo of Blue coupe'));
});

it('AC-1 skips photos whose hash and label are current and re-embeds a changed caption', function (): void {
    $photo = photoWithCaption('Silver SUV');
    fakeCaptionEncoder();
    $this->artisan('photos:embed', ['--text' => true])->assertSuccessful();

    $this->artisan('photos:embed', ['--text' => true])->expectsOutputToContain('Queued 0 jobs for 0 captions')->assertSuccessful();

    PhotoExtraction::query()->where('photo_id', $photo->id)->update(['data' => ['caption' => 'Silver SUV in snow']]);
    $this->artisan('photos:embed', ['--text' => true])->expectsOutputToContain('Queued 1 jobs for 1 captions')->assertSuccessful();

    expect($photo->refresh()->text_hash)->toBe(hash('sha256', 'a photo of Silver SUV in snow'));

    ListingPhoto::query()->whereKey($photo->id)->update(['text_embedding_model' => 'photo-encoder/other']);
    $this->artisan('photos:embed', ['--text' => true])->expectsOutputToContain('Queued 1 jobs for 1 captions')->assertSuccessful();

    expect($photo->refresh()->text_embedding_model)->toBe(CAPTION_LABEL);
});

it('AC-1 leaves a photo without a caption null and never encodes the listing title', function (): void {
    $photo = photoWithCaption(null);
    $photo->listing()->update(['make' => 'Zzyzx', 'model' => 'Unique']);
    fakeCaptionEncoder();

    $this->artisan('photos:embed', ['--text' => true, '--wait' => true])
        ->expectsOutputToContain('1 photos have no caption')
        ->assertSuccessful();

    expect($photo->refresh()->text_embedding)->toBeNull()->and($photo->text_source)->toBeNull();
    Http::assertSentCount(1);
    expect(sentCaptionTexts())->toBe([]);
});

it('AC-1 has no title source option', function (): void {
    photoWithCaption(null);
    fakeCaptionEncoder();

    expect(fn () => $this->artisan('photos:embed', ['--text' => true, '--text-source' => 'title'])->run())->toThrow(InvalidOptionException::class);
});

it('AC-1 sets the vector to null when the current extraction has no caption any more', function (): void {
    $photo = photoWithCaption('Black pickup');
    fakeCaptionEncoder();
    $this->artisan('photos:embed', ['--text' => true])->assertSuccessful();
    expect($photo->refresh()->text_embedding)->not->toBeNull();

    PhotoExtraction::query()->where('photo_id', $photo->id)->delete();
    $this->artisan('photos:embed', ['--text' => true])->assertSuccessful();

    $photo->refresh();
    expect($photo->text_embedding)->toBeNull()->and($photo->text_hash)->toBeNull()->and($photo->text_embedding_model)->toBeNull();
});

it('AC-1 queues 20 captions as two jobs on ai-vision', function (): void {
    foreach (range(1, 20) as $number) {
        photoWithCaption("Car number {$number}");
    }
    fakeCaptionEncoder();

    Queue::fake();
    $this->artisan('photos:embed', ['--text' => true])->assertSuccessful();
    Queue::assertPushedOn('ai-vision', EmbedPhotoTextsBatchJob::class);
    Queue::assertPushed(EmbedPhotoTextsBatchJob::class, 2);
});

it('AC-1 records one PhotoTextEncoder run per batch', function (): void {
    foreach (range(1, 20) as $number) {
        photoWithCaption("Car number {$number}");
    }
    fakeCaptionEncoder();

    $this->artisan('photos:embed', ['--text' => true, '--wait' => true])->assertSuccessful();

    $runs = AiRun::query()->where('agent', 'PhotoTextEncoder')->orderBy('id')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('inputs')->all())->toBe([16, 4])
        ->and($runs->pluck('status')->unique()->all())->toBe(['completed'])
        ->and(ListingPhoto::query()->whereNotNull('text_embedding')->count())->toBe(20);
});

it('AC-1 clears and re-embeds with --fresh and --limit only touches the first photos', function (): void {
    $photos = collect(range(1, 3))->map(fn (int $n): ListingPhoto => photoWithCaption("Car {$n}"));
    fakeCaptionEncoder();

    $this->artisan('photos:embed', ['--text' => true, '--limit' => 2])->assertSuccessful();
    expect(ListingPhoto::query()->whereNotNull('text_embedding')->pluck('id')->sort()->values()->all())
        ->toBe($photos->take(2)->pluck('id')->sort()->values()->all());

    $this->artisan('photos:embed', ['--text' => true, '--fresh' => true])->expectsOutputToContain('Queued 1 jobs for 3 captions')->assertSuccessful();
    expect(ListingPhoto::query()->whereNotNull('text_embedding')->count())->toBe(3);
});

it('AC-1 bumps the text generation only when a run changes a row', function (): void {
    $similar = app(SimilarPhotosService::class);
    $before = $similar->textGeneration(CAPTION_LABEL);
    photoWithCaption('Orange convertible');
    fakeCaptionEncoder();

    $this->artisan('photos:embed', ['--text' => true])->assertSuccessful();
    $after = $similar->textGeneration(CAPTION_LABEL);

    $this->artisan('photos:embed', ['--text' => true])->assertSuccessful();

    expect($after)->toBeGreaterThan($before)
        ->and($similar->textGeneration(CAPTION_LABEL))->toBe($after)
        ->and($similar->generation(CAPTION_LABEL))->toBe(1);
});

it('AC-1 records a failed run and keeps the vectors when the encoder fails', function (): void {
    $photo = photoWithCaption('Yellow van');
    Http::fake(['encoder.test:7997/embeddings' => Http::sequence()->push(['data' => array_map(fn (int $i): array => ['index' => $i, 'embedding' => array_fill(0, 512, 0.1)], [0])])->push([], 500)]);

    $this->artisan('photos:embed', ['--text' => true, '--wait' => true])->assertExitCode(1);

    expect($photo->refresh()->text_embedding)->toBeNull()
        ->and(AiRun::query()->where('agent', 'PhotoTextEncoder')->value('status'))->toBe('failed');
});
