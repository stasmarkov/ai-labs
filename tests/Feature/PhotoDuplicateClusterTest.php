<?php

declare(strict_types=1);

use App\Contracts\DuplicateGroupRepositoryInterface;
use App\Contracts\ListingPhotoRepositoryInterface;
use App\DTOs\PhotoEmbedding;
use App\DTOs\SimilarPhotoMatch;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Services\DuplicateClusterService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

const CLUSTER_LABEL = 'photo-encoder/openai/clip-vit-base-patch32';

beforeEach(function (): void {
    config([
        'ai.features.embeddings-photos.provider' => 'photo-encoder',
        'ai.features.embeddings-photos.model' => null,
        'ai.photo_duplicates.cutoff' => 0.98,
        'ai.photo_duplicates.max_group' => 6,
    ]);
});

/**
 * A unit vector turned by the given degrees inside the plane of an axis pair; two vectors of one pair have the cosine
 * of the angle between them as their similarity, vectors of different pairs are orthogonal.
 *
 * @return list<float>
 */
function clusterVector(int $pair, float $degrees): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[2 * $pair] = cos(deg2rad($degrees));
    $vector[2 * $pair + 1] = sin(deg2rad($degrees));

    return $vector;
}

/**
 * Create a photo. A vector is held by a fixed-neighbour repository that ranks the registered photos by exact cosine
 * similarity, so the clustering never depends on the approximate vector index; the groups are still stored by the real
 * repository. With $indexed the vector goes through the real index instead.
 *
 * @param  list<float>|null  $vector
 */
function clusterPhoto(?array $vector, ?Listing $listing = null, string $label = CLUSTER_LABEL, string $source = ListingPhoto::SOURCE_STANFORD_CARS, bool $indexed = false): ListingPhoto
{
    $photo = ListingPhoto::factory()->create([
        'listing_id' => ($listing ?? Listing::factory()->create())->id,
        'source' => $source,
    ]);

    if ($vector === null) {
        return $photo;
    }

    if ($indexed) {
        app(ListingPhotoRepositoryInterface::class)->storeEmbeddings([new PhotoEmbedding($photo->id, $vector)], $label, CarbonImmutable::now());

        return $photo;
    }

    clusterNeighbours()->register($photo, $vector, $label);

    return $photo;
}

function clusterNeighbours(): ClusterNeighbours
{
    $bound = app(DuplicateGroupRepositoryInterface::class);

    if ($bound instanceof ClusterNeighbours) {
        return $bound;
    }

    $fake = new ClusterNeighbours($bound);
    app()->instance(DuplicateGroupRepositoryInterface::class, $fake);

    return $fake;
}

final class ClusterNeighbours implements DuplicateGroupRepositoryInterface
{
    /** @var array<int, array{photo: ListingPhoto, vector: list<float>, label: string}> */
    private array $entries = [];

    public function __construct(private readonly DuplicateGroupRepositoryInterface $real) {}

    public function deleteGroupsHolding(string $source): void
    {
        $this->real->deleteGroupsHolding($source);
    }

    /**
     * @param  list<float>  $vector
     */
    public function register(ListingPhoto $photo, array $vector, string $label): void
    {
        $this->entries[$photo->id] = ['photo' => $photo, 'vector' => $vector, 'label' => $label];
    }

    public function forget(ListingPhoto $photo): void
    {
        unset($this->entries[$photo->id]);
    }

    public function photoIdsWithVector(string $label): array
    {
        $ids = array_keys(array_filter($this->entries, fn (array $entry): bool => $entry['label'] === $label));
        sort($ids);

        return $ids;
    }

    public function nearest(int $photoId, string $label, int $limit, int $efSearch): array
    {
        $from = $this->entries[$photoId]['vector'];
        $matches = [];

        foreach ($this->entries as $id => $entry) {
            if ($entry['label'] !== $label) {
                continue;
            }

            $dot = 0.0;

            foreach ($from as $axis => $value) {
                $dot += $value * $entry['vector'][$axis];
            }

            $matches[] = [$id, $dot];
        }

        usort($matches, fn (array $a, array $b): int => [$b[1], $a[0]] <=> [$a[1], $b[0]]);

        return array_map(
            fn (array $match): SimilarPhotoMatch => new SimilarPhotoMatch($match[0], $this->entries[$match[0]]['photo']->listing_id, '', '', '', $this->entries[$match[0]]['photo']->path, $match[1]),
            array_slice($matches, 0, $limit),
        );
    }

    public function replaceGroups(string $label, float $cutoff, array $groups): int
    {
        return $this->real->replaceGroups($label, $cutoff, $groups);
    }
}

/**
 * @return list<list<int>> the photo ids of each stored group, ascending, groups by their first id
 */
function storedGroups(): array
{
    $groups = DB::table('photo_duplicate_members')->orderBy('photo_id')->get()->groupBy('group_id')
        ->map(fn ($members): array => $members->pluck('photo_id')->map(fn ($id): int => (int) $id)->all())
        ->values()->all();
    usort($groups, fn (array $a, array $b): int => $a[0] <=> $b[0]);

    return $groups;
}

it('AC-3 stores the connected components of pairs at or above the cutoff, chaining through a middle photo', function (): void {
    // 0 and 16 degrees fall below 0.98 (0.961); both reach the photo at 8 degrees (0.990), so one component.
    $chain = [clusterPhoto(clusterVector(0, 0)), clusterPhoto(clusterVector(0, 8)), clusterPhoto(clusterVector(0, 16))];
    $pair = [clusterPhoto(clusterVector(1, 0)), clusterPhoto(clusterVector(1, 1))];
    clusterPhoto(clusterVector(2, 0));
    clusterPhoto(null);

    $report = app(DuplicateClusterService::class)->rebuild();

    expect(storedGroups())->toBe([
        array_map(fn (ListingPhoto $photo): int => $photo->id, $chain),
        array_map(fn (ListingPhoto $photo): int => $photo->id, $pair),
    ])
        ->and($report->photos)->toBe(6)
        ->and($report->groups)->toBe(2)
        ->and($report->photosGrouped)->toBe(5)
        ->and($report->splitGroups)->toBe(0)
        ->and($report->cutoff)->toBe(0.98)
        ->and($report->label)->toBe(CLUSTER_LABEL);

    $stored = DB::table('photo_duplicate_groups')->get();

    expect($stored)->toHaveCount(2)
        ->and($stored->pluck('label')->unique()->all())->toBe([CLUSTER_LABEL])
        ->and($stored->pluck('cutoff')->unique()->all())->toEqual([0.98])
        ->and($stored->pluck('was_split')->unique()->all())->toBe([false]);
});

it('AC-3 stores the strongest similarity of each member', function (): void {
    $first = clusterPhoto(clusterVector(0, 0));
    $second = clusterPhoto(clusterVector(0, 8));
    $third = clusterPhoto(clusterVector(0, 9));

    app(DuplicateClusterService::class)->rebuild();

    $members = DB::table('photo_duplicate_members')->pluck('max_similarity', 'photo_id');

    expect($members[$first->id])->toEqualWithDelta(cos(deg2rad(8)), 1e-5)
        ->and($members[$second->id])->toEqualWithDelta(cos(deg2rad(1)), 1e-5)
        ->and($members[$third->id])->toEqualWithDelta(cos(deg2rad(1)), 1e-5);
});

it('AC-3 keeps a pair exactly on the cutoff and drops one just below it', function (): void {
    $edge = rad2deg(acos(0.98));
    $below = rad2deg(acos(0.9795));

    $onEdge = [clusterPhoto(clusterVector(0, 0)), clusterPhoto(clusterVector(0, $edge))];
    clusterPhoto(clusterVector(1, 0));
    clusterPhoto(clusterVector(1, $below));

    app(DuplicateClusterService::class)->rebuild();

    expect(storedGroups())->toBe([[$onEdge[0]->id, $onEdge[1]->id]]);
});

it('AC-3 follows the configured cutoff', function (): void {
    $first = clusterPhoto(clusterVector(0, 0));
    $second = clusterPhoto(clusterVector(0, 12));

    app(DuplicateClusterService::class)->rebuild();

    expect(storedGroups())->toBe([]);

    config(['ai.photo_duplicates.cutoff' => 0.97]);
    app(DuplicateClusterService::class)->rebuild();

    expect(storedGroups())->toBe([[$first->id, $second->id]])
        ->and(DB::table('photo_duplicate_groups')->value('cutoff'))->toEqual(0.97);
});

it('AC-3 groups photos of one listing and augmented copies', function (): void {
    $listing = Listing::factory()->create();
    $original = clusterPhoto(clusterVector(0, 0), $listing);
    $copy = clusterPhoto(clusterVector(0, 1), $listing, source: ListingPhoto::SOURCE_AUGMENTED);
    $otherListing = clusterPhoto(clusterVector(0, 2));

    app(DuplicateClusterService::class)->rebuild();

    expect(storedGroups())->toBe([[$original->id, $copy->id, $otherListing->id]]);
});

it('AC-3 ignores vectors of another label in the index', function (): void {
    $raw = array_map(fn (): float => random_int(-1000, 1000) / 1000, range(1, 512));
    $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $raw)));
    $vector = array_map(fn (float $value): float => $value / $norm, $raw);

    $first = clusterPhoto($vector, indexed: true);
    $second = clusterPhoto($vector, indexed: true);
    $foreign = clusterPhoto($vector, label: 'photo-encoder/another-model', indexed: true);

    app(DuplicateClusterService::class)->rebuild();

    $grouped = collect(storedGroups())->flatten()->all();
    $nearest = collect(app(DuplicateGroupRepositoryInterface::class)->nearest($first->id, CLUSTER_LABEL, 20, 100))->pluck('photoId')->all();

    expect($grouped)->not->toContain($foreign->id)
        ->and($nearest)->not->toContain($foreign->id)
        ->and(app(DuplicateGroupRepositoryInterface::class)->photoIdsWithVector(CLUSTER_LABEL))->toContain($first->id, $second->id)
        ->and(app(DuplicateGroupRepositoryInterface::class)->photoIdsWithVector(CLUSTER_LABEL))->not->toContain($foreign->id);
});

it('AC-3 caps a group at 6 by cutting its weakest link', function (): void {
    // Two tight blocks of four joined by cross pairs around 0.985; the strongest cross pair is the only tree bridge.
    $first = array_map(fn (float $degrees): ListingPhoto => clusterPhoto(clusterVector(0, $degrees)), [0, 0.5, 1, 1.5]);
    $second = array_map(fn (float $degrees): ListingPhoto => clusterPhoto(clusterVector(0, $degrees)), [10, 10.5, 11, 11.5]);

    $report = app(DuplicateClusterService::class)->rebuild();

    $ids = fn (array $photos): array => array_map(fn (ListingPhoto $photo): int => $photo->id, $photos);

    expect(storedGroups())->toBe([$ids($first), $ids($second)])
        ->and($report->groups)->toBe(2)
        ->and($report->splitGroups)->toBe(2)
        ->and(DB::table('photo_duplicate_groups')->pluck('was_split')->unique()->all())->toBe([true]);
});

it('AC-3 never stores a group above the cap and drops photos left alone by the split', function (): void {
    // A chain of eight photos, 5 degrees apart: neighbours reach 0.996, two apart only 0.985, below the 0.99 cutoff.
    config(['ai.photo_duplicates.cutoff' => 0.99]);
    $chain = array_map(fn (int $step): ListingPhoto => clusterPhoto(clusterVector(0, $step * 5.0)), range(0, 7));

    app(DuplicateClusterService::class)->rebuild();

    $groups = storedGroups();

    expect($groups)->not->toBe([])
        ->and(max(array_map('count', $groups)))->toBeLessThanOrEqual(6)
        ->and(collect($groups)->flatten()->unique()->count())->toBe(collect($groups)->flatten()->count())
        ->and(collect($groups)->flatten()->diff(collect($chain)->pluck('id'))->all())->toBe([]);
});

it('AC-3 splits the same way on every run', function (): void {
    foreach ([0, 0.5, 1, 1.5, 10, 10.5, 11, 11.5] as $degrees) {
        clusterPhoto(clusterVector(0, $degrees));
    }

    app(DuplicateClusterService::class)->rebuild();
    $first = storedGroups();
    app(DuplicateClusterService::class)->rebuild();

    expect(storedGroups())->toBe($first);
});

it('AC-3 replaces the groups of the label on a rebuild and leaves other labels alone', function (): void {
    $first = clusterPhoto(clusterVector(0, 0));
    $second = clusterPhoto(clusterVector(0, 1));
    $other = DB::table('photo_duplicate_groups')->insertGetId(['label' => 'photo-encoder/another-model', 'cutoff' => 0.9, 'was_split' => false]);

    $service = app(DuplicateClusterService::class);
    $built = $service->rebuild();
    $firstGroupId = (int) DB::table('photo_duplicate_groups')->where('label', CLUSTER_LABEL)->value('id');
    $third = clusterPhoto(clusterVector(0, 2));
    $rebuilt = $service->rebuild();

    expect($built->replacedGroups)->toBe(0)
        ->and($rebuilt->replacedGroups)->toBe(1)
        ->and(storedGroups())->toBe([[$first->id, $second->id, $third->id]])
        ->and(DB::table('photo_duplicate_groups')->where('label', CLUSTER_LABEL)->count())->toBe(1)
        ->and(DB::table('photo_duplicate_groups')->where('label', CLUSTER_LABEL)->value('id'))->not->toBe($firstGroupId)
        ->and(DB::table('photo_duplicate_groups')->where('id', $other)->exists())->toBeTrue();
});

it('AC-3 removes a stale group when the photos no longer pair up', function (): void {
    clusterPhoto(clusterVector(0, 0));
    $second = clusterPhoto(clusterVector(0, 1));

    app(DuplicateClusterService::class)->rebuild();
    $second->delete();
    clusterNeighbours()->forget($second);
    app(DuplicateClusterService::class)->rebuild();

    expect(storedGroups())->toBe([]);
});

it('NFR-3 runs one nearest query per photo and clusters 300 photos in under 5 seconds', function (): void {
    $listing = Listing::factory()->create();
    $photos = ListingPhoto::factory()->count(300)->create(['listing_id' => $listing->id]);
    mt_srand(11);
    $bases = [];

    foreach (range(0, 149) as $index) {
        $raw = array_map(fn (): float => mt_rand(-1000, 1000) / 1000, range(1, 512));
        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $raw)));
        $bases[$index] = array_map(fn (float $value): float => $value / $norm, $raw);
    }

    // Photos 150 to 299 are a hair away from photos 0 to 149, so 150 pairs.
    $embeddings = $photos->values()->map(function (ListingPhoto $photo, int $index) use ($bases): PhotoEmbedding {
        $vector = $bases[$index % 150];

        if ($index >= 150) {
            $vector[0] += 0.001;
        }

        return new PhotoEmbedding($photo->id, $vector);
    })->all();
    app(ListingPhotoRepositoryInterface::class)->storeEmbeddings($embeddings, CLUSTER_LABEL, CarbonImmutable::now());

    $nearestQueries = 0;
    DB::listen(function ($query) use (&$nearestQueries): void {
        if (str_contains($query->sql, '<=>')) {
            $nearestQueries++;
        }
    });

    $report = app(DuplicateClusterService::class)->rebuild();

    expect($nearestQueries)->toBe(300)
        ->and($report->photos)->toBe(300)
        ->and($report->groups)->toBe(150)
        ->and($report->elapsedMs)->toBeLessThan(5000);
});

it('AC-3 runs from the command and reports the groups', function (): void {
    clusterPhoto(clusterVector(0, 0));
    clusterPhoto(clusterVector(0, 1));

    $this->artisan('photos:duplicates')
        ->expectsOutputToContain('Groups: 1 (2 photos, 0 split to fit the cap)')
        ->assertSuccessful();
});

it('AC-3 fails from the command when no photo has a vector of the current label', function (): void {
    clusterPhoto(null);

    $this->artisan('photos:duplicates')
        ->expectsOutputToContain('photos:embed')
        ->assertFailed();
});
