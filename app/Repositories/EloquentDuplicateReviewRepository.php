<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\DuplicateReviewRepositoryInterface;
use App\DTOs\DuplicateJudgeRequest;
use App\DTOs\DuplicateReviewGroup;
use App\DTOs\DuplicateReviewJudgement;
use App\DTOs\DuplicateReviewMark;
use App\DTOs\DuplicateReviewPhoto;
use App\DTOs\DuplicateReviewSummary;
use App\Enums\DuplicateReviewSource;
use App\Enums\PhotoReviewStatus;
use App\Models\ListingPhoto;
use App\Models\PhotoDuplicateJudgement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class EloquentDuplicateReviewRepository implements DuplicateReviewRepositoryInterface
{
    public function page(string $label, DuplicateJudgeRequest $judging, int $page, int $perPage): LengthAwarePaginator
    {
        $paginator = DB::table('photo_duplicate_groups')
            ->where('label', $label)
            ->orderBy('id')
            ->paginate($perPage, ['id', 'was_split'], page: $page);

        $ids = array_map(fn (object $row): int => (int) $row->id, $paginator->items());
        $cards = $this->cards($ids, $judging);

        return $paginator->through(fn (object $row): DuplicateReviewGroup => $cards[(int) $row->id]);
    }

    public function summary(string $label, DuplicateJudgeRequest $judging): DuplicateReviewSummary
    {
        $groups = DB::table('photo_duplicate_groups')->where('label', $label);

        return new DuplicateReviewSummary(
            (clone $groups)->count(),
            $this->judgementsOf($judging)->whereIn('group_id', (clone $groups)->select('id'))->where('status', PhotoDuplicateJudgement::STATUS_COMPLETED)->count(),
            DB::table('photo_duplicate_reviews')->whereIn('group_id', (clone $groups)->select('id'))->count(),
            DB::table('photo_duplicate_members')
                ->join('listing_photos', 'listing_photos.id', '=', 'photo_duplicate_members.photo_id')
                ->whereIn('photo_duplicate_members.group_id', (clone $groups)->select('id'))
                ->where('listing_photos.review_status', PhotoReviewStatus::MarkedForRemoval->value)
                ->count(),
        );
    }

    public function find(int $groupId, DuplicateJudgeRequest $judging): ?DuplicateReviewGroup
    {
        return $this->cards([$groupId], $judging)[$groupId] ?? null;
    }

    public function memberIds(int $groupId, ?string $label = null): ?array
    {
        if (! DB::table('photo_duplicate_groups')->where('id', $groupId)->when($label !== null, fn ($query) => $query->where('label', $label))->exists()) {
            return null;
        }

        return DB::table('photo_duplicate_members')
            ->where('group_id', $groupId)
            ->orderBy('photo_id')
            ->pluck('photo_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function reviewedPhotoIds(string $label): array
    {
        return DB::table('photo_duplicate_members')
            ->join('photo_duplicate_reviews', 'photo_duplicate_reviews.group_id', '=', 'photo_duplicate_members.group_id')
            ->join('photo_duplicate_groups', 'photo_duplicate_groups.id', '=', 'photo_duplicate_members.group_id')
            ->where('photo_duplicate_groups.label', $label)
            ->orderBy('photo_duplicate_members.photo_id')
            ->pluck('photo_duplicate_members.photo_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function pickedPhotoId(int $groupId, DuplicateJudgeRequest $judging): ?int
    {
        $picked = $this->judgementsOf($judging)
            ->where('group_id', $groupId)
            ->where('status', PhotoDuplicateJudgement::STATUS_COMPLETED)
            ->value('best_photo_id');

        return $picked === null ? null : (int) $picked;
    }

    public function saveReview(int $groupId, int $keptPhotoId, DuplicateReviewSource $source, array $removePhotoIds): void
    {
        DB::transaction(function () use ($groupId, $keptPhotoId, $source, $removePhotoIds): void {
            $members = $this->memberQuery($groupId);

            ListingPhoto::query()->whereIn('id', $members)->update(['review_status' => null]);
            ListingPhoto::query()->whereKey($keptPhotoId)->update(['review_status' => PhotoReviewStatus::Kept->value]);
            ListingPhoto::query()->whereIn('id', $removePhotoIds)->update(['review_status' => PhotoReviewStatus::MarkedForRemoval->value]);

            DB::table('photo_duplicate_reviews')->upsert(
                [['group_id' => $groupId, 'kept_photo_id' => $keptPhotoId, 'source' => $source->value, 'reviewed_at' => now()]],
                ['group_id'],
                ['kept_photo_id', 'source', 'reviewed_at'],
            );
        });
    }

    public function clearReview(int $groupId): void
    {
        DB::transaction(function () use ($groupId): void {
            ListingPhoto::query()->whereIn('id', $this->memberQuery($groupId))->update(['review_status' => null]);
            DB::table('photo_duplicate_reviews')->where('group_id', $groupId)->delete();
        });
    }

    /**
     * @return Builder
     */
    private function memberQuery(int $groupId)
    {
        return DB::table('photo_duplicate_members')->where('group_id', $groupId)->select('photo_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<PhotoDuplicateJudgement>
     */
    private function judgementsOf(DuplicateJudgeRequest $judging)
    {
        return PhotoDuplicateJudgement::query()
            ->where('provider', $judging->provider)
            ->where('model', $judging->model)
            ->where('prompt_version', $judging->promptVersion);
    }

    /**
     * @param  list<int>  $groupIds
     * @return array<int, DuplicateReviewGroup>
     */
    private function cards(array $groupIds, DuplicateJudgeRequest $judging): array
    {
        if ($groupIds === []) {
            return [];
        }

        $groups = DB::table('photo_duplicate_groups')->whereIn('id', $groupIds)->get()->keyBy('id');

        $members = DB::table('photo_duplicate_members')
            ->join('listing_photos', 'listing_photos.id', '=', 'photo_duplicate_members.photo_id')
            ->join('listings', 'listings.id', '=', 'listing_photos.listing_id')
            ->whereIn('photo_duplicate_members.group_id', $groupIds)
            ->orderBy('listing_photos.id')
            ->get([
                'photo_duplicate_members.group_id',
                'photo_duplicate_members.max_similarity',
                'listing_photos.id',
                'listing_photos.path',
                'listing_photos.source',
                'listing_photos.variant',
                'listing_photos.review_status',
                'listings.year',
                'listings.make',
                'listings.model',
            ])
            ->groupBy('group_id');

        $judgements = $this->judgementsOf($judging)->whereIn('group_id', $groupIds)->get()->keyBy('group_id');
        $reviews = DB::table('photo_duplicate_reviews')->whereIn('group_id', $groupIds)->get()->keyBy('group_id');

        $cards = [];

        foreach ($groupIds as $groupId) {
            /** @var Collection<int, object> $rows */
            $rows = $members->get($groupId, collect());
            $review = $reviews->get($groupId);
            $judgement = $judgements->get($groupId);

            $cards[$groupId] = new DuplicateReviewGroup(
                $groupId,
                (bool) ($groups->get($groupId)->was_split ?? false),
                $rows->map(fn (object $row): DuplicateReviewPhoto => new DuplicateReviewPhoto(
                    (int) $row->id,
                    $this->url((string) $row->path),
                    "{$row->year} {$row->make} {$row->model}",
                    (float) $row->max_similarity,
                    $row->variant === null ? null : (string) $row->variant,
                    $row->source === ListingPhoto::SOURCE_AUGMENTED ? 'augmented' : 'original',
                    $row->review_status === null ? null : (string) $row->review_status,
                ))->values()->all(),
                $judgement === null ? null : $this->judgement($judgement),
                $review === null ? null : new DuplicateReviewMark((int) $review->kept_photo_id, (string) $review->source, CarbonImmutable::parse($review->reviewed_at)->toIso8601String()),
            );
        }

        return $cards;
    }

    private function judgement(PhotoDuplicateJudgement $judgement): DuplicateReviewJudgement
    {
        $invocationIds = $judgement->invocation_ids ?? [];

        return new DuplicateReviewJudgement(
            $judgement->status,
            $judgement->provider,
            $judgement->model,
            $judgement->prompt_version,
            $judgement->attempts,
            $judgement->latency_ms,
            $judgement->best_photo_id,
            $judgement->reasons ?? [],
            $judgement->issues ?? [],
            $judgement->errors,
            $invocationIds === [] ? null : route('ai.runs.show', ['invocationId' => $invocationIds[array_key_last($invocationIds)]], false),
        );
    }

    /**
     * The public path of a stored photo, relative so it works on any host.
     */
    private function url(string $path): string
    {
        return (string) parse_url(Storage::disk('public')->url($path), PHP_URL_PATH);
    }
}
