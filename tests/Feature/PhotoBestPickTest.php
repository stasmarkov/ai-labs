<?php

declare(strict_types=1);

use App\Ai\PhotoBestPickAgent;
use App\Contracts\DuplicateGroupRepositoryInterface;
use App\DTOs\SimilarPhotoMatch;
use App\Jobs\JudgeDuplicateGroupJob;
use App\Models\AiRun;
use App\Models\ListingPhoto;
use App\Models\PhotoDuplicateJudgement;
use App\Services\DuplicateJudgementService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Files\Image;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * A stored group of the given photos, and a queued judgement for it on the configured provider and model.
 *
 * @param  list<ListingPhoto>  $photos
 */
function judgementOf(array $photos): PhotoDuplicateJudgement
{
    $groupId = DB::table('photo_duplicate_groups')->insertGetId(['label' => 'photo-encoder/test', 'cutoff' => 0.98]);
    DB::table('photo_duplicate_members')->insert(array_map(
        fn (ListingPhoto $photo): array => ['group_id' => $groupId, 'photo_id' => $photo->id, 'max_similarity' => 0.99],
        $photos,
    ));
    $agent = new PhotoBestPickAgent;

    return PhotoDuplicateJudgement::factory()->create([
        'group_id' => $groupId,
        'provider' => $agent->providerName(),
        'model' => $agent->modelName(),
        'prompt_version' => $agent->promptVersion(),
    ]);
}

/**
 * @return list<ListingPhoto> ascending by id
 */
function groupPhotos(int $count = 3): array
{
    return ListingPhoto::factory()->count($count)->create()->sortBy('id')->values()->all();
}

function runJudgement(PhotoDuplicateJudgement $judgement): PhotoDuplicateJudgement
{
    (new JudgeDuplicateGroupJob($judgement->id))->handle(app(DuplicateJudgementService::class));

    return $judgement->refresh();
}

/**
 * @param  list<ListingPhoto>  $photos
 * @param  array<string, mixed>  $change
 * @return array<string, mixed>
 */
function pick(array $photos, array $change = []): array
{
    return [
        'best_photo_id' => $photos[0]->id,
        'reasons' => ['Sharpest and full size.'],
        'issues' => array_map(
            fn (ListingPhoto $photo): array => ['photo_id' => $photo->id, 'problem' => 'compression'],
            array_slice($photos, 1),
        ),
        ...$change,
    ];
}

it('AC-4 sends every photo of the group with its id in one call and stores the checked pick', function (): void {
    $photos = groupPhotos(3);
    $judgement = judgementOf($photos);
    PhotoBestPickAgent::fake([pick($photos)]);

    $judgement = runJudgement($judgement);

    expect($judgement->status)->toBe('completed')
        ->and($judgement->attempts)->toBe(1)
        ->and($judgement->best_photo_id)->toBe($photos[0]->id)
        ->and($judgement->reasons)->toBe(['Sharpest and full size.'])
        ->and($judgement->issues)->toEqual([
            ['photo_id' => $photos[1]->id, 'problem' => 'compression'],
            ['photo_id' => $photos[2]->id, 'problem' => 'compression'],
        ])
        ->and($judgement->errors)->toBeNull()
        ->and($judgement->invocation_ids)->toHaveCount(1)
        ->and($judgement->latency_ms)->toBeInt();

    PhotoBestPickAgent::assertPromptedTimes(1);
    PhotoBestPickAgent::assertPrompted(function ($prompt) use ($photos): bool {
        $paths = $prompt->attachments->map(fn ($file): string => $file instanceof Image ? (string) $file->path : '')->all();
        $lines = array_map(fn (ListingPhoto $photo): string => "Photo id {$photo->id}:", $photos);

        return $paths === array_map(fn (ListingPhoto $photo): string => $photo->path, $photos)
            && array_filter($lines, fn (string $line): bool => str_contains($prompt->prompt, $line)) === $lines;
    });
});

it('AC-4 rejects a pick outside the group, retries and stores the valid second answer', function (): void {
    $photos = groupPhotos(3);
    $outsider = ListingPhoto::factory()->create();
    PhotoBestPickAgent::fake([pick($photos, ['best_photo_id' => $outsider->id]), pick($photos, ['best_photo_id' => $photos[2]->id, 'issues' => [
        ['photo_id' => $photos[0]->id, 'problem' => 'blurry'],
        ['photo_id' => $photos[1]->id, 'problem' => 'cropped'],
    ]])]);

    $judgement = runJudgement(judgementOf($photos));

    expect($judgement->status)->toBe('completed')
        ->and($judgement->attempts)->toBe(2)
        ->and($judgement->best_photo_id)->toBe($photos[2]->id);

    PhotoBestPickAgent::assertPromptedTimes(2);
});

it('AC-4 rejects an issue about a photo outside the group, about the best photo, or with an unknown problem', function (string $case): void {
    $photos = groupPhotos(2);
    $outsider = ListingPhoto::factory()->create();
    $change = match ($case) {
        'outside photo' => ['issues' => [['photo_id' => $outsider->id, 'problem' => 'blurry']]],
        'the best photo itself' => ['issues' => [['photo_id' => $photos[0]->id, 'problem' => 'blurry']]],
        'unknown problem' => ['issues' => [['photo_id' => $photos[1]->id, 'problem' => 'ugly']]],
        'no reasons' => ['reasons' => []],
        'long reason' => ['reasons' => [str_repeat('a', 121)]],
        'five reasons' => ['reasons' => ['a', 'b', 'c', 'd', 'e']],
        'string id' => ['best_photo_id' => 'first'],
    };
    $answer = pick($photos, $change);
    PhotoBestPickAgent::fake([$answer, $answer, $answer]);

    $judgement = runJudgement(judgementOf($photos));

    expect($judgement->status)->toBe('failed')
        ->and($judgement->attempts)->toBe(3);
})->with(['outside photo', 'the best photo itself', 'unknown problem', 'no reasons', 'long reason', 'five reasons', 'string id']);

it('AC-4 marks the group failed with the last errors after three bad answers and never calls a fourth time', function (): void {
    $photos = groupPhotos(2);
    PhotoBestPickAgent::fake([
        pick($photos, ['best_photo_id' => 999999]),
        pick($photos, ['reasons' => []]),
        pick($photos, ['best_photo_id' => 999998]),
        pick($photos),
    ]);

    $judgement = runJudgement(judgementOf($photos));

    expect($judgement->status)->toBe('failed')
        ->and($judgement->attempts)->toBe(3)
        ->and($judgement->best_photo_id)->toBeNull()
        ->and(array_keys($judgement->errors))->toBe(['best_photo_id'])
        ->and($judgement->invocation_ids)->toHaveCount(3);

    PhotoBestPickAgent::assertPromptedTimes(3);
});

it('AC-4 treats an empty answer as an invalid attempt and retries', function (): void {
    $photos = groupPhotos(2);
    PhotoBestPickAgent::fake([fn (): array => [], pick($photos)]);

    $judgement = runJudgement(judgementOf($photos));

    expect($judgement->status)->toBe('completed')
        ->and($judgement->attempts)->toBe(2);
    expect(AiRun::query()->orderBy('id')->pluck('status')->all())->toBe(['failed', 'completed']);
});

it('AC-4 records a transport error as one failed attempt with no retry', function (): void {
    $photos = groupPhotos(2);
    PhotoBestPickAgent::fake([fn () => throw new ConnectionException('cURL error 7: Connection refused')]);

    $judgement = runJudgement(judgementOf($photos));

    expect($judgement->status)->toBe('failed')
        ->and($judgement->attempts)->toBe(1)
        ->and($judgement->errors['transport'][0])->toBe(ConnectionException::class)
        ->and($judgement->errors['transport'][1])->toContain('Connection refused');

    PhotoBestPickAgent::assertPromptedTimes(1);
    expect(AiRun::query()->pluck('status')->all())->toBe(['failed']);
});

it('AC-4 writes one ai_runs row per attempt and flags the invalid ones', function (): void {
    $photos = groupPhotos(3);
    PhotoBestPickAgent::fake([pick($photos, ['best_photo_id' => 0]), pick($photos, ['reasons' => []]), pick($photos)]);

    $judgement = runJudgement(judgementOf($photos));
    $runs = AiRun::query()->whereIn('invocation_id', $judgement->invocation_ids)->orderBy('id')->get();

    expect($runs)->toHaveCount(3)
        ->and($runs->pluck('validation_failed')->all())->toBe([true, true, false])
        ->and($runs->pluck('agent')->unique()->all())->toBe(['PhotoBestPickAgent'])
        ->and($runs->pluck('prompt_version')->unique()->all())->toBe(['photo-best-pick@v1'])
        ->and($runs->pluck('status')->unique()->all())->toBe(['completed'])
        ->and($runs->pluck('model')->unique()->all())->toBe(['gemma4:12b']);
});

it('AC-4 runs on the photo-vision provider and model, and fails a job queued for another one', function (): void {
    $photos = groupPhotos(2);
    $judgement = judgementOf($photos);
    $judgement->update(['model' => 'another-model:1b']);
    PhotoBestPickAgent::fake([pick($photos)]);

    $judgement = runJudgement($judgement);

    expect($judgement->status)->toBe('failed')
        ->and($judgement->errors['transport'][0])->toBe('ProviderChanged');

    PhotoBestPickAgent::assertNeverPrompted();
});

it('NFR-2 judges on ai-vision without queue retries', function (): void {
    expect(JudgeDuplicateGroupJob::QUEUE)->toBe('ai-vision')
        ->and((new JudgeDuplicateGroupJob(1))->queue)->toBe('ai-vision')
        ->and((new JudgeDuplicateGroupJob(1))->tries)->toBe(1);
});

/**
 * Replaces the nearest-vector step of the clustering with fixed neighbours, so the command tests do not depend on the
 * vector index: every photo of a list is the neighbour of the others at 0.99.
 *
 * @param  list<list<ListingPhoto>>  $groups
 */
function fakeNeighbours(array $groups): void
{
    $real = app(DuplicateGroupRepositoryInterface::class);

    app()->instance(DuplicateGroupRepositoryInterface::class, new class($groups, $real) implements DuplicateGroupRepositoryInterface
    {
        /**
         * @param  list<list<ListingPhoto>>  $groups
         */
        public function __construct(private array $groups, private DuplicateGroupRepositoryInterface $real) {}

        public function deleteGroupsHolding(string $source): void
        {
            $this->real->deleteGroupsHolding($source);
        }

        public function photoIdsWithVector(string $label): array
        {
            $ids = array_merge(...array_map(fn (array $group): array => array_map(fn (ListingPhoto $photo): int => $photo->id, $group), $this->groups));
            sort($ids);

            return $ids;
        }

        public function nearest(int $photoId, string $label, int $limit, int $efSearch): array
        {
            foreach ($this->groups as $group) {
                foreach ($group as $photo) {
                    if ($photo->id === $photoId) {
                        return array_map(
                            fn (ListingPhoto $mate): SimilarPhotoMatch => new SimilarPhotoMatch($mate->id, $mate->listing_id, '', '', '', $mate->path, $mate->id === $photoId ? 1.0 : 0.99),
                            $group,
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

it('NFR-2 queues one job per group on ai-vision from the command', function (): void {
    Queue::fake();
    $photos = groupPhotos(4);
    fakeNeighbours([[$photos[0], $photos[1]], [$photos[2], $photos[3]]]);

    $this->artisan('photos:duplicates --judge')
        ->expectsOutputToContain('Queued 2 groups on ai-vision')
        ->assertSuccessful();

    Queue::assertPushedOn('ai-vision', JudgeDuplicateGroupJob::class);
    Queue::assertPushed(JudgeDuplicateGroupJob::class, 2);
    expect(PhotoDuplicateJudgement::query()->pluck('status')->unique()->all())->toBe(['queued']);
});

it('NFR-2 judges at most 6 images of at most 1024 px and refuses anything else before a call', function (array $sizes): void {
    $photos = array_map(
        fn (array $size): ListingPhoto => ListingPhoto::factory()->create(['width' => $size[0], 'height' => $size[1]]),
        $sizes,
    );
    usort($photos, fn (ListingPhoto $a, ListingPhoto $b): int => $a->id <=> $b->id);
    PhotoBestPickAgent::fake([pick($photos)]);

    $judgement = runJudgement(judgementOf($photos));

    expect($judgement->status)->toBe('failed')
        ->and($judgement->attempts)->toBe(0)
        ->and(array_keys($judgement->errors))->toBe(['group']);

    PhotoBestPickAgent::assertNeverPrompted();
})->with([
    'seven photos' => [array_fill(0, 7, [1024, 768])],
    'one photo over 1024 px' => [[[1024, 768], [1025, 700]]],
]);

it('NFR-2 sends all six photos of a full group in one call', function (): void {
    $photos = groupPhotos(6);
    PhotoBestPickAgent::fake([pick($photos)]);

    $judgement = runJudgement(judgementOf($photos));

    expect($judgement->status)->toBe('completed');
    PhotoBestPickAgent::assertPrompted(fn ($prompt): bool => $prompt->attachments->count() === 6);
});

it('AC-4 judges and waits from the command with a faked agent', function (): void {
    $photos = groupPhotos(2);
    fakeNeighbours([$photos]);
    PhotoBestPickAgent::fake([pick($photos)]);

    $this->artisan('photos:duplicates --judge --wait')
        ->expectsOutputToContain('Judged: 1 completed, 0 failed.')
        ->assertSuccessful();

    expect(PhotoDuplicateJudgement::query()->pluck('best_photo_id')->all())->toBe([$photos[0]->id]);
});

it('AC-4 refuses --wait without --judge', function (): void {
    $this->artisan('photos:duplicates --wait')->assertExitCode(2);
});
