<?php

declare(strict_types=1);

use App\Contracts\ListingPhotoRepositoryInterface;
use App\Contracts\PhotoEvalRepositoryInterface;
use App\Contracts\PhotoExtractionRepositoryInterface;
use App\Contracts\PhotoSearchEvalRepositoryInterface;
use App\Contracts\SimilarEvalRepositoryInterface;
use App\Contracts\SimilarPhotosRepositoryInterface;
use App\DTOs\PhotoEmbedding;
use App\DTOs\PhotoExtractionRequest;
use App\DTOs\VectorQuery;
use App\Enums\PhotoVariant;
use App\Models\ListingPhoto;
use App\Models\PhotoExtraction;
use App\Repositories\PhotoVectorRepository;
use App\Support\PhotoVariantRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const DUPLICATES_LABEL = 'photo-encoder/test-model';

beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * @return list<float>
 */
function duplicatesVector(float $score): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[0] = $score;
    $vector[1] = sqrt(1 - $score * $score);

    return $vector;
}

function importedPhotosWithVectors(int $count): void
{
    $photos = ListingPhoto::factory()->count($count)->create();
    $embeddings = [];

    foreach ($photos as $photo) {
        $image = imagecreatetruecolor(160, 120);

        for ($x = 0; $x < 160; $x += 4) {
            imagefilledrectangle($image, $x, 0, $x + 3, 119, imagecolorallocate($image, ($x * 7 + $photo->id * 31) % 256, ($x * 3 + $photo->id * 17) % 256, 90));
        }

        ob_start();
        imagejpeg($image, null, 90);
        Storage::disk('public')->put($photo->path, (string) ob_get_clean());
        $embeddings[] = new PhotoEmbedding($photo->id, duplicatesVector(0.5 + ($photo->id % 400) / 1000));
    }

    app(ListingPhotoRepositoryInterface::class)->storeEmbeddings($embeddings, DUPLICATES_LABEL, CarbonImmutable::now());
}

/**
 * @return list<array{0: int|null, 1: string|null, 2: string}>
 */
function augmentedSignature(): array
{
    return ListingPhoto::query()->where('source', 'augmented')->orderBy('id')->get()
        ->map(fn (ListingPhoto $photo): array => [$photo->duplicate_of, $photo->variant?->value, $photo->sha256])
        ->all();
}

it('AC-1 writes 2 to 4 distinct variant kinds per original on the same listing, with a file and a new sha256', function (): void {
    importedPhotosWithVectors(6);

    $this->artisan('photos:make-duplicates', ['--photos' => 4])->assertSuccessful();

    $variants = ListingPhoto::query()->where('source', 'augmented')->get();
    $originals = $variants->pluck('duplicate_of')->unique();

    expect($originals)->toHaveCount(4);

    foreach ($originals as $originalId) {
        $original = ListingPhoto::query()->findOrFail($originalId);
        $own = $variants->where('duplicate_of', $originalId);

        expect($own->count())->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4)
            ->and($own->map(fn (ListingPhoto $v): string => $v->variant->value)->unique())->toHaveCount($own->count())
            ->and($own->pluck('variant')->filter(fn ($kind): bool => ! $kind instanceof PhotoVariant))->toBeEmpty();

        foreach ($own as $variant) {
            expect($variant->listing_id)->toBe($original->listing_id)
                ->and($variant->sha256)->not->toBe($original->sha256)
                ->and($variant->embedding)->toBeNull()
                ->and(Storage::disk('public')->exists($variant->path))->toBeTrue()
                ->and(hash('sha256', Storage::disk('public')->get($variant->path)))->toBe($variant->sha256);
        }
    }
});

it('AC-1 renders every kind and never repeats the original bytes', function (): void {
    importedPhotosWithVectors(1);
    $original = ListingPhoto::query()->firstOrFail();
    $bytes = Storage::disk('public')->get($original->path);
    $renderer = app(PhotoVariantRenderer::class);
    $hashes = [];

    foreach (PhotoVariant::cases() as $kind) {
        $image = $renderer->render($bytes, $kind);
        $hashes[] = hash('sha256', $image->bytes);

        expect($image->width)->toBe(160)->and($image->height)->toBe(120);
    }

    expect(array_unique($hashes))->toHaveCount(5)->and($hashes)->not->toContain($original->sha256);
});

it('AC-1 is deterministic for a seed and differs for another seed', function (): void {
    importedPhotosWithVectors(8);

    $this->artisan('photos:make-duplicates', ['--photos' => 5, '--seed' => 3])->assertSuccessful();
    $first = augmentedSignature();

    $this->artisan('photos:make-duplicates', ['--photos' => 5, '--seed' => 3, '--fresh' => true])->assertSuccessful();
    expect(augmentedSignature())->toBe($first);

    $this->artisan('photos:make-duplicates', ['--photos' => 5, '--seed' => 99, '--fresh' => true])->assertSuccessful();
    expect(array_column(augmentedSignature(), 0))->not->toBe(array_column($first, 0));
});

it('AC-1 --fresh removes only augmented rows and their files', function (): void {
    importedPhotosWithVectors(4);
    $this->artisan('photos:make-duplicates', ['--photos' => 2])->assertSuccessful();

    $augmentedPaths = ListingPhoto::query()->where('source', 'augmented')->pluck('path')->all();
    $importedPaths = ListingPhoto::query()->where('source', '<>', 'augmented')->pluck('path')->all();
    expect($augmentedPaths)->not->toBeEmpty();

    $this->artisan('photos:make-duplicates', ['--photos' => 1, '--fresh' => true])->assertSuccessful();

    foreach ($importedPaths as $path) {
        expect(Storage::disk('public')->exists($path))->toBeTrue();
    }

    expect(ListingPhoto::query()->where('source', '<>', 'augmented')->count())->toBe(4);
    $rewritten = ListingPhoto::query()->where('source', 'augmented');

    expect((clone $rewritten)->distinct()->pluck('duplicate_of'))->toHaveCount(1)
        ->and((clone $rewritten)->count())->toBeGreaterThanOrEqual(2);

    // The old copies' files are gone, not just their rows (new copies get new ids, so new paths).
    $current = (clone $rewritten)->pluck('path')->all();

    foreach (array_diff($augmentedPaths, $current) as $path) {
        expect(Storage::disk('public')->exists($path))->toBeFalse("{$path} survived --fresh");
    }
});

it('AC-1 only picks imported photos that already have an image vector', function (): void {
    $bare = ListingPhoto::factory()->create();
    Storage::disk('public')->put($bare->path, 'x');

    $this->artisan('photos:make-duplicates', ['--photos' => 3])->assertSuccessful();

    expect(ListingPhoto::query()->where('source', 'augmented')->count())->toBe(0);
});

it('AC-1 rejects a bad option', function (): void {
    $this->artisan('photos:make-duplicates', ['--photos' => 0])->assertExitCode(2);
});

it('AC-2 an augmented photo never appears in the nearest query unless asked for', function (): void {
    importedPhotosWithVectors(3);
    $reference = ListingPhoto::query()->orderBy('id')->firstOrFail();
    $augmented = ListingPhoto::factory()->create(['source' => 'augmented', 'duplicate_of' => $reference->id, 'variant' => 'darkened', 'listing_id' => $reference->listing_id]);
    app(ListingPhotoRepositoryInterface::class)->storeEmbeddings([new PhotoEmbedding($augmented->id, duplicatesVector(0.5 + ($reference->id % 400) / 1000))], DUPLICATES_LABEL, CarbonImmutable::now());

    $vectors = app(PhotoVectorRepository::class);
    $ids = fn (VectorQuery $query): array => array_map(fn ($match): int => $match->photoId, $vectors->nearest($query));

    expect($ids(VectorQuery::fromPhoto($reference->id, DUPLICATES_LABEL, 10, 40)))->not->toContain($augmented->id)
        ->and($ids(VectorQuery::fromVector(duplicatesVector(0.5), DUPLICATES_LABEL, 10, 40)))->not->toContain($augmented->id)
        ->and($ids(VectorQuery::fromPhoto($reference->id, DUPLICATES_LABEL, 10, 40, excludeAugmented: false)))->toContain($augmented->id)
        ->and(app(SimilarPhotosRepositoryInterface::class)->nearest($reference->id, DUPLICATES_LABEL, 10, 40))
        ->each(fn ($match) => $match->photoId->not->toBe($augmented->id));
});

it('AC-2 the extraction, eval and coverage queries of the earlier features count imported photos only', function (): void {
    importedPhotosWithVectors(3);
    $reference = ListingPhoto::query()->orderBy('id')->firstOrFail();
    $augmented = ListingPhoto::factory()->create(['source' => 'augmented', 'duplicate_of' => $reference->id, 'variant' => 'mirrored']);
    app(ListingPhotoRepositoryInterface::class)->storeEmbeddings([new PhotoEmbedding($augmented->id, duplicatesVector(0.9))], DUPLICATES_LABEL, CarbonImmutable::now());

    $extraction = app(PhotoExtractionRepositoryInterface::class);
    $request = new PhotoExtractionRequest('p', 'm', 'v1');

    expect($extraction->pendingPhotoIds($request, null))->not->toContain($augmented->id)
        ->and($extraction->countPhotos())->toBe(3)
        ->and(app(PhotoSearchEvalRepositoryInterface::class)->embeddedPhotoIds(DUPLICATES_LABEL))->not->toContain($augmented->id)
        ->and(array_map(fn ($photo): int => $photo->photoId, app(SimilarEvalRepositoryInterface::class)->embeddedPhotos(DUPLICATES_LABEL)))->not->toContain($augmented->id);
});

it('MNC-3 the photo grid tags augmented photos and leaves imported ones untagged', function (): void {
    $original = ListingPhoto::factory()->create();
    ListingPhoto::factory()->create(['source' => 'augmented', 'duplicate_of' => $original->id, 'variant' => 'cropped', 'listing_id' => $original->listing_id]);

    $this->get('/ai/photos')->assertInertia(fn ($page) => $page
        ->has('photos', 2)
        ->where('photos.0.variant', null)
        ->where('photos.1.variant', 'cropped'));
});

it('AC-2 MNC-1 MNC-3 the photo eval, similar eval and caption queries skip an augmented photo that would otherwise qualify', function (): void {
    importedPhotosWithVectors(3);
    $reference = ListingPhoto::query()->orderBy('id')->firstOrFail();
    $augmented = ListingPhoto::factory()->create(['source' => 'augmented', 'duplicate_of' => $reference->id, 'variant' => 'mirrored']);
    app(ListingPhotoRepositoryInterface::class)->storeEmbeddings([new PhotoEmbedding($augmented->id, duplicatesVector(0.9))], DUPLICATES_LABEL, CarbonImmutable::now());

    $listing = $augmented->listing()->firstOrFail();
    $listing->forceFill(['year' => 1990, 'make' => 'Augmentedmake', 'model' => 'Augmentedmodel'])->save();

    $extraction = PhotoExtraction::factory()->for($augmented, 'photo')->create([
        'status' => PhotoExtraction::STATUS_COMPLETED,
        'data' => ['caption' => 'a mirrored copy'],
    ]);
    $request = new PhotoExtractionRequest($extraction->provider, $extraction->model, $extraction->prompt_version);
    $photos = app(ListingPhotoRepositoryInterface::class);

    expect(app(PhotoEvalRepositoryInterface::class)->countPhotos())->toBe(3)
        ->and(array_map(fn ($sample): int => $sample->photoId, app(PhotoEvalRepositoryInterface::class)->sample(50)))->not->toContain($augmented->id)
        ->and(app(SimilarEvalRepositoryInterface::class)->embeddedTitles(DUPLICATES_LABEL))->not->toContain('1990 Augmentedmake Augmentedmodel')
        ->and(array_map(fn ($row): int => $row->photoId, $photos->captionRows($request, null)))->not->toContain($augmented->id)
        ->and(array_map(fn ($row): int => $row->photoId, $photos->captionRowsFor($request, [$augmented->id])))->toBe([]);
});

it('AC-1 --fresh removes the groups that held a copy and clears the marks their review wrote', function (): void {
    importedPhotosWithVectors(2);
    $this->artisan('photos:make-duplicates', ['--photos' => 1])->assertSuccessful();
    $copy = ListingPhoto::query()->where('source', 'augmented')->firstOrFail();
    $original = ListingPhoto::query()->findOrFail($copy->duplicate_of);
    $groupId = DB::table('photo_duplicate_groups')->insertGetId(['label' => 'photo-encoder/test', 'cutoff' => 0.92]);
    DB::table('photo_duplicate_members')->insert([
        ['group_id' => $groupId, 'photo_id' => $original->id, 'max_similarity' => 0.95],
        ['group_id' => $groupId, 'photo_id' => $copy->id, 'max_similarity' => 0.95],
    ]);
    DB::table('photo_duplicate_reviews')->insert(['group_id' => $groupId, 'kept_photo_id' => $copy->id, 'source' => 'override', 'reviewed_at' => now()]);
    DB::table('listing_photos')->where('id', $original->id)->update(['review_status' => 'marked_for_removal']);

    $this->artisan('photos:make-duplicates', ['--photos' => 1, '--fresh' => true])->assertSuccessful();

    expect(DB::table('photo_duplicate_groups')->where('id', $groupId)->exists())->toBeFalse()
        ->and($original->refresh()->review_status)->toBeNull();
});
