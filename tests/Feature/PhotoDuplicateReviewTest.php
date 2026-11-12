<?php

declare(strict_types=1);

use App\Contracts\DuplicateGroupRepositoryInterface;
use App\DTOs\SimilarPhotoMatch;
use App\Enums\PhotoVariant;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Models\PhotoDuplicateJudgement;
use App\Services\DuplicateClusterService;
use App\Services\DuplicateJudgementService;
use App\Services\DuplicateReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

const REVIEW_LABEL = 'photo-encoder/openai/clip-vit-base-patch32';

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->withoutVite();
    config([
        'ai.features.embeddings-photos.provider' => 'photo-encoder',
        'ai.features.embeddings-photos.model' => null,
        'ai.photo_duplicates.cutoff' => 0.98,
    ]);
});

/**
 * A stored group of one original and augmented variants on one listing; the first member is the original.
 *
 * @return array{group: int, photos: list<ListingPhoto>}
 */
function reviewGroup(int $size = 3, string $label = REVIEW_LABEL): array
{
    $listing = Listing::factory()->create(['make' => 'Ford', 'model' => 'Fiesta', 'year' => 2012]);
    $variants = [PhotoVariant::Downscaled, PhotoVariant::Mirrored, PhotoVariant::Darkened, PhotoVariant::Cropped, PhotoVariant::Recompressed];
    $original = ListingPhoto::factory()->for($listing)->create();
    $photos = [$original];

    for ($index = 1; $index < $size; $index++) {
        $photos[] = ListingPhoto::factory()->for($listing)->create([
            'source' => ListingPhoto::SOURCE_AUGMENTED,
            'duplicate_of' => $original->id,
            'variant' => $variants[$index - 1],
        ]);
    }

    $groupId = DB::table('photo_duplicate_groups')->insertGetId(['label' => $label, 'cutoff' => 0.98]);

    foreach ($photos as $index => $photo) {
        DB::table('photo_duplicate_members')->insert(['group_id' => $groupId, 'photo_id' => $photo->id, 'max_similarity' => 0.99 - $index / 100]);
    }

    return ['group' => $groupId, 'photos' => $photos];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function reviewJudgement(int $groupId, array $attributes = []): PhotoDuplicateJudgement
{
    $request = app(DuplicateJudgementService::class)->request();

    return PhotoDuplicateJudgement::factory()->create([
        'group_id' => $groupId,
        'provider' => $request->provider,
        'model' => $request->model,
        'prompt_version' => $request->promptVersion,
        'status' => PhotoDuplicateJudgement::STATUS_COMPLETED,
        'attempts' => 1,
        'latency_ms' => 4200,
        'invocation_ids' => ['run-judge-1'],
        ...$attributes,
    ]);
}

function reviewStatuses(): array
{
    return ListingPhoto::query()->orderBy('id')->pluck('review_status', 'id')->all();
}

it('AC-6 the page shows each group with similarities, tags, the pick, reasons, issues, the run link and the summary', function (): void {
    ['group' => $groupId, 'photos' => [$original, $downscaled, $mirrored]] = reviewGroup();
    reviewJudgement($groupId, [
        'best_photo_id' => $original->id,
        'reasons' => ['Sharpest of the three.', 'Evenly lit.'],
        'issues' => [['photo_id' => $downscaled->id, 'problem' => 'low_resolution'], ['photo_id' => $mirrored->id, 'problem' => 'mirrored']],
    ]);

    $this->get('/ai/photos/duplicates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/PhotoDuplicates')
            ->has('howItWorks')
            ->where('guideHref', '/ai/guides/photo-duplicates')
            ->where('label', REVIEW_LABEL)
            ->where('cutoff', 0.98)
            ->where('summary', ['groups' => 1, 'judged' => 1, 'reviewed' => 0, 'markedForRemoval' => 0])
            ->where('pagination', ['page' => 1, 'lastPage' => 1, 'total' => 1])
            ->has('groups', 1)
            ->where('groups.0.id', $groupId)
            ->where('groups.0.wasSplit', false)
            ->has('groups.0.photos', 3)
            ->where('groups.0.photos.0.id', $original->id)
            ->where('groups.0.photos.0.source', 'original')
            ->where('groups.0.photos.0.variant', null)
            ->where('groups.0.photos.0.similarity', 0.99)
            ->where('groups.0.photos.0.title', '2012 Ford Fiesta')
            ->where('groups.0.photos.0.url', '/storage/'.$original->path)
            ->where('groups.0.photos.1.source', 'augmented')
            ->where('groups.0.photos.1.variant', 'downscaled')
            ->where('groups.0.photos.2.variant', 'mirrored')
            ->where('groups.0.judgement.status', 'completed')
            ->where('groups.0.judgement.bestPhotoId', $original->id)
            ->where('groups.0.judgement.reasons', ['Sharpest of the three.', 'Evenly lit.'])
            ->where('groups.0.judgement.issues', [['photo_id' => $downscaled->id, 'problem' => 'low_resolution'], ['photo_id' => $mirrored->id, 'problem' => 'mirrored']])
            ->where('groups.0.judgement.runHref', '/ai/runs/run-judge-1')
            ->where('groups.0.review', null)
            ->where('keepUrl', fn (string $url) => str_contains($url, '/ai/photos/duplicates/GROUP_ID/keep'))
            ->where('undoUrl', fn (string $url) => str_contains($url, '/ai/photos/duplicates/GROUP_ID/undo')));
});

it('AC-6 a group with no judgement, a failed judgement or one from another model has no pick', function (): void {
    ['group' => $unjudged] = reviewGroup(2);
    ['group' => $failed] = reviewGroup(2);
    ['group' => $other] = reviewGroup(2);
    reviewJudgement($failed, ['status' => PhotoDuplicateJudgement::STATUS_FAILED, 'best_photo_id' => null, 'errors' => ['best_photo_id' => ['Not in the group.']]]);
    reviewJudgement($other, ['model' => 'some-other-model', 'best_photo_id' => ListingPhoto::query()->value('id')]);

    $this->get('/ai/photos/duplicates')
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.groups', 3)
            ->where('summary.judged', 0)
            ->where('groups.0.id', $unjudged)
            ->where('groups.0.judgement', null)
            ->where('groups.1.judgement.status', 'failed')
            ->where('groups.1.judgement.bestPhotoId', null)
            ->where('groups.1.judgement.errors', ['best_photo_id' => ['Not in the group.']])
            ->where('groups.2.id', $other)
            ->where('groups.2.judgement', null));
});

it('AC-6 the page shows only the groups of the current vector label, ten to a page', function (): void {
    reviewGroup(2, 'photo-encoder/another-model');

    foreach (range(1, 12) as $ignored) {
        reviewGroup(2);
    }

    $this->get('/ai/photos/duplicates')
        ->assertInertia(fn (Assert $page) => $page
            ->has('groups', 10)
            ->where('pagination', ['page' => 1, 'lastPage' => 2, 'total' => 12])
            ->where('summary.groups', 12));

    $this->get('/ai/photos/duplicates?page=2')
        ->assertInertia(fn (Assert $page) => $page->has('groups', 2)->where('pagination.page', 2));
});

it('AC-6 an empty database shows the empty state facts', function (): void {
    $this->get('/ai/photos/duplicates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('groups', 0)
            ->where('summary', ['groups' => 0, 'judged' => 0, 'reviewed' => 0, 'markedForRemoval' => 0])
            ->where('commands', ['ddev artisan photos:make-duplicates --photos=20', 'ddev artisan photos:embed --wait', 'ddev artisan photos:duplicates --judge --wait']));
});

it('AC-6 the page source shows the cards, pick, actions, summary bar, how it works and empty state', function (): void {
    $source = (string) file_get_contents(resource_path('js/ai/Pages/Lab/PhotoDuplicates.vue'));

    expect($source)->toContain('LabHowItWorks')
        ->toContain('LabTag')
        ->toContain('Keep this one, remove')
        ->toContain('Pick another')
        ->toContain('Undo')
        ->toContain('No duplicate groups yet')
        ->toContain('summary');
});

it('AC-6 the lab lists the page in the navigation and as an overview capability with a guide', function (): void {
    $overview = $this->get('/ai')->viewData('page')['props'];
    $keys = array_column($overview['capabilities'], 'key');

    expect($keys)->toContain('photo-duplicates');

    $capability = $overview['capabilities'][array_search('photo-duplicates', $keys, true)];

    expect($capability['route'])->toBe('/ai/photos/duplicates')
        ->and($capability['status'])->toBe('ready')
        ->and($capability['guideHref'])->toBe('/ai/guides/photo-duplicates');

    $this->get($capability['guideHref'])->assertOk();

    $navigation = $this->get('/ai/photos/duplicates')->viewData('page')['props']['lab']['navigation'] ?? [];

    expect(array_column($navigation, 'href'))->toContain('/ai/photos/duplicates');
});

it('AC-5 keeping the model pick marks the kept photo and the others and deletes nothing', function (): void {
    ['group' => $groupId, 'photos' => [$original, $darkened, $mirrored]] = reviewGroup();
    reviewJudgement($groupId, ['best_photo_id' => $original->id, 'reasons' => ['Sharpest.'], 'issues' => []]);

    $this->postJson("/ai/photos/duplicates/{$groupId}/keep", ['photo_id' => $original->id])
        ->assertOk()
        ->assertJsonPath('group.id', $groupId)
        ->assertJsonPath('group.review.keptPhotoId', $original->id)
        ->assertJsonPath('group.review.source', 'model')
        ->assertJsonPath('group.photos.0.reviewStatus', 'kept')
        ->assertJsonPath('group.photos.1.reviewStatus', 'marked_for_removal')
        ->assertJsonPath('summary', ['groups' => 1, 'judged' => 1, 'reviewed' => 1, 'markedForRemoval' => 2]);

    expect(reviewStatuses())->toBe([$original->id => 'kept', $darkened->id => 'marked_for_removal', $mirrored->id => 'marked_for_removal'])
        ->and(DB::table('photo_duplicate_reviews')->where('group_id', $groupId)->value('source'))->toBe('model');

    $this->get('/ai/photos/duplicates')->assertInertia(fn (Assert $page) => $page
        ->where('summary', ['groups' => 1, 'judged' => 1, 'reviewed' => 1, 'markedForRemoval' => 2])
        ->where('groups.0.review.keptPhotoId', $original->id)
        ->where('groups.0.photos.2.reviewStatus', 'marked_for_removal'));
});

it('AC-5 picking another photo stores the override next to the model pick', function (): void {
    ['group' => $groupId, 'photos' => [$original, $darkened, $mirrored]] = reviewGroup();
    reviewJudgement($groupId, ['best_photo_id' => $original->id, 'reasons' => ['Sharpest.'], 'issues' => []]);

    $this->postJson("/ai/photos/duplicates/{$groupId}/keep", ['photo_id' => $mirrored->id])
        ->assertOk()
        ->assertJsonPath('group.review.keptPhotoId', $mirrored->id)
        ->assertJsonPath('group.review.source', 'override')
        ->assertJsonPath('group.judgement.bestPhotoId', $original->id);

    expect(reviewStatuses())->toBe([$original->id => 'marked_for_removal', $darkened->id => 'marked_for_removal', $mirrored->id => 'kept']);
});

it('AC-5 keeping again replaces the earlier marks instead of adding to them', function (): void {
    ['group' => $groupId, 'photos' => [$original, $darkened]] = reviewGroup(2);

    $this->postJson("/ai/photos/duplicates/{$groupId}/keep", ['photo_id' => $original->id])->assertOk();
    $this->postJson("/ai/photos/duplicates/{$groupId}/keep", ['photo_id' => $darkened->id])->assertOk();

    expect(reviewStatuses())->toBe([$original->id => 'marked_for_removal', $darkened->id => 'kept'])
        ->and(DB::table('photo_duplicate_reviews')->where('group_id', $groupId)->count())->toBe(1)
        ->and(DB::table('photo_duplicate_reviews')->where('group_id', $groupId)->value('source'))->toBe('override');
});

it('AC-5 undo restores the unmarked state and removes the review', function (): void {
    ['group' => $groupId, 'photos' => [$original]] = reviewGroup();
    ['group' => $otherGroup, 'photos' => [$otherOriginal]] = reviewGroup(2);

    $this->postJson("/ai/photos/duplicates/{$groupId}/keep", ['photo_id' => $original->id])->assertOk();
    $this->postJson("/ai/photos/duplicates/{$otherGroup}/keep", ['photo_id' => $otherOriginal->id])->assertOk();

    $this->postJson("/ai/photos/duplicates/{$groupId}/undo")
        ->assertOk()
        ->assertJsonPath('group.review', null)
        ->assertJsonPath('summary.reviewed', 1)
        ->assertJsonPath('summary.markedForRemoval', 1);

    expect(ListingPhoto::query()->whereIn('id', array_column(DB::table('photo_duplicate_members')->where('group_id', $groupId)->get()->all(), 'photo_id'))->whereNotNull('review_status')->count())->toBe(0)
        ->and(DB::table('photo_duplicate_reviews')->where('group_id', $groupId)->exists())->toBeFalse()
        ->and(DB::table('photo_duplicate_reviews')->where('group_id', $otherGroup)->exists())->toBeTrue();

    $this->postJson("/ai/photos/duplicates/{$groupId}/undo")->assertOk();
});

it('NFR-1 a photo outside the group is refused and nothing is marked', function (): void {
    ['group' => $groupId] = reviewGroup(2);
    ['photos' => [$stranger]] = reviewGroup(2);

    $this->postJson("/ai/photos/duplicates/{$groupId}/keep", ['photo_id' => $stranger->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('photo_id');

    $this->postJson("/ai/photos/duplicates/{$groupId}/keep", ['photo_id' => 999999])->assertStatus(422);

    expect(array_filter(reviewStatuses()))->toBe([])
        ->and(DB::table('photo_duplicate_reviews')->count())->toBe(0);
});

it('NFR-1 the actions take a whole photo id only and a known group', function (mixed $body): void {
    ['group' => $groupId] = reviewGroup(2);

    $this->postJson("/ai/photos/duplicates/{$groupId}/keep", $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('photo_id');
})->with([
    'missing' => [[]],
    'text' => [['photo_id' => 'abc']],
    'array' => [['photo_id' => [1]]],
    'zero' => [['photo_id' => 0]],
    'fraction' => [['photo_id' => 1.5]],
]);

it('NFR-1 an unknown group answers not found on both actions', function (): void {
    $this->postJson('/ai/photos/duplicates/999999/keep', ['photo_id' => 1])->assertNotFound();
    $this->postJson('/ai/photos/duplicates/999999/undo')->assertNotFound();
    $this->postJson('/ai/photos/duplicates/abc/keep', ['photo_id' => 1])->assertNotFound();
});

it('MNC-5 keeping, overriding and undoing delete no file and no row', function (): void {
    Storage::fake('public');
    ['group' => $groupId, 'photos' => $photos] = reviewGroup();
    reviewJudgement($groupId, ['best_photo_id' => $photos[0]->id, 'reasons' => ['Sharpest.'], 'issues' => []]);

    foreach ($photos as $photo) {
        Storage::disk('public')->put($photo->path, 'jpeg-bytes');
    }

    $tables = ['listing_photos', 'photo_duplicate_groups', 'photo_duplicate_members', 'photo_duplicate_judgements'];
    $before = array_map(fn (string $table): int => DB::table($table)->count(), $tables);
    $deletes = [];
    DB::listen(function ($query) use (&$deletes): void {
        if (preg_match('/^\s*delete\b/i', $query->sql) === 1) {
            $deletes[] = $query->sql;
        }
    });

    $this->postJson("/ai/photos/duplicates/{$groupId}/keep", ['photo_id' => $photos[0]->id])->assertOk();
    $this->postJson("/ai/photos/duplicates/{$groupId}/keep", ['photo_id' => $photos[2]->id])->assertOk();
    $this->postJson("/ai/photos/duplicates/{$groupId}/undo")->assertOk();

    foreach ($photos as $photo) {
        Storage::disk('public')->assertExists($photo->path);
    }

    expect(array_map(fn (string $table): int => DB::table($table)->count(), $tables))->toBe($before)
        ->and(array_filter($deletes, fn (string $sql): bool => ! str_contains($sql, 'photo_duplicate_reviews')))->toBe([]);
});

it('MNC-5 the review code never reaches the storage disk to delete', function (): void {
    foreach (['Services/DuplicateReviewService', 'Http/Controllers/KeepDuplicatePhotoController', 'Http/Controllers/UndoDuplicateReviewController', 'Repositories/EloquentDuplicateReviewRepository'] as $file) {
        $source = (string) file_get_contents(app_path("{$file}.php"));

        expect($source)->not->toMatch('/Storage::[a-zA-Z]*(delete|deleteDirectory)|->delete(Directory)?\(\s*\$[a-z]*[pP]ath|unlink\(/');
    }
});

/**
 * Replace the nearest-vector step with fixed neighbours, as in the best-pick tests: every photo of a list is the
 * neighbour of the others at 0.99, and the groups are stored by the real repository. Real vectors would leave dead
 * entries in the shared vector index of the test database.
 *
 * @param  list<list<ListingPhoto>>  $lists
 */
function reviewFakeNeighbours(array $lists): void
{
    $real = app(DuplicateGroupRepositoryInterface::class);

    app()->instance(DuplicateGroupRepositoryInterface::class, new class($lists, $real) implements DuplicateGroupRepositoryInterface
    {
        /**
         * @param  list<list<ListingPhoto>>  $lists
         */
        public function __construct(private array $lists, private DuplicateGroupRepositoryInterface $real) {}

        public function deleteGroupsHolding(string $source): void
        {
            $this->real->deleteGroupsHolding($source);
        }

        public function photoIdsWithVector(string $label): array
        {
            $ids = array_merge(...array_map(fn (array $list): array => array_map(fn (ListingPhoto $photo): int => $photo->id, $list), $this->lists));
            sort($ids);

            return $ids;
        }

        public function nearest(int $photoId, string $label, int $limit, int $efSearch): array
        {
            foreach ($this->lists as $list) {
                foreach ($list as $photo) {
                    if ($photo->id === $photoId) {
                        return array_map(
                            fn (ListingPhoto $mate): SimilarPhotoMatch => new SimilarPhotoMatch($mate->id, $mate->listing_id, '', '', '', $mate->path, $mate->id === $photoId ? 1.0 : 0.99),
                            $list,
                        );
                    }
                }
            }

            return [];
        }

        public function replaceGroups(string $label, float $cutoff, array $groups): int
        {
            return $this->real->replaceGroups($label, $cutoff, $groups);
        }
    });
}

it('AC-5 a rebuild keeps a reviewed group with its marks and judgement and replaces only the unreviewed ones', function (): void {
    ['group' => $reviewed, 'photos' => [$kept, $removed]] = reviewGroup(2);
    ['group' => $stale, 'photos' => [$staleA, $staleB]] = reviewGroup(2);
    $judgement = reviewJudgement($reviewed, ['best_photo_id' => $kept->id, 'reasons' => ['Sharpest.'], 'issues' => []]);
    $this->postJson("/ai/photos/duplicates/{$reviewed}/keep", ['photo_id' => $kept->id])->assertOk();

    $newcomer = ListingPhoto::factory()->create();
    reviewFakeNeighbours([[$kept, $removed, $newcomer], [$staleA, $staleB]]);

    $report = app(DuplicateClusterService::class)->rebuild();

    $members = fn (int $id): array => DB::table('photo_duplicate_members')->where('group_id', $id)->orderBy('photo_id')->pluck('photo_id')->all();

    expect(DB::table('photo_duplicate_groups')->where('id', $reviewed)->exists())->toBeTrue()
        ->and($members($reviewed))->toBe([$kept->id, $removed->id])
        ->and(DB::table('photo_duplicate_groups')->where('id', $stale)->exists())->toBeFalse()
        ->and(PhotoDuplicateJudgement::query()->whereKey($judgement->id)->exists())->toBeTrue()
        ->and(reviewStatuses()[$kept->id])->toBe('kept')
        ->and(reviewStatuses()[$removed->id])->toBe('marked_for_removal')
        ->and($report->replacedGroups)->toBe(1)
        ->and(DB::table('photo_duplicate_members')->where('photo_id', $newcomer->id)->exists())->toBeFalse()
        ->and(DB::table('photo_duplicate_members')->whereIn('photo_id', [$staleA->id, $staleB->id])->count())->toBe(2)
        ->and(DB::table('photo_duplicate_groups')->count())->toBe(2);
});

it('AC-4 photos:duplicates --judge skips reviewed groups and groups already judged by the same model and prompt', function (): void {
    Queue::fake();
    $reviewed = reviewGroup();
    reviewJudgement($reviewed['group'], ['best_photo_id' => $reviewed['photos'][0]->id]);
    DB::table('photo_duplicate_reviews')->insert(['group_id' => $reviewed['group'], 'kept_photo_id' => $reviewed['photos'][0]->id, 'source' => 'model', 'reviewed_at' => now()]);
    $judged = reviewGroup();
    reviewJudgement($judged['group'], ['best_photo_id' => $judged['photos'][1]->id]);
    $fresh = reviewGroup();

    $label = app(DuplicateReviewService::class)->label();
    $service = app(DuplicateJudgementService::class);

    expect($service->groupIds($label, $service->request()))->toBe([$fresh['group']])
        ->and(PhotoDuplicateJudgement::query()->where('group_id', $reviewed['group'])->value('best_photo_id'))->toBe($reviewed['photos'][0]->id);
});

it('NFR-1 keep and undo refuse a group built for another vector label', function (): void {
    $other = reviewGroup(3, 'photo-encoder/another-model');

    $this->postJson("/ai/photos/duplicates/{$other['group']}/keep", ['photo_id' => $other['photos'][0]->id])->assertNotFound();
    $this->postJson("/ai/photos/duplicates/{$other['group']}/undo")->assertNotFound();
});

it('NFR-1 an overlong group id is a 404, not a server error', function (): void {
    $this->postJson('/ai/photos/duplicates/999999999999999999999/keep', ['photo_id' => 1])->assertNotFound();
});
