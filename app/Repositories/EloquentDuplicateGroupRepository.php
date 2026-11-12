<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\DuplicateGroupRepositoryInterface;
use App\DTOs\VectorQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class EloquentDuplicateGroupRepository implements DuplicateGroupRepositoryInterface
{
    public function __construct(private PhotoVectorRepository $vectors) {}

    public function photoIdsWithVector(string $label): array
    {
        return DB::table('listing_photos')
            ->whereNotNull('embedding')
            ->where('embedding_model', $label)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function nearest(int $photoId, string $label, int $limit, int $efSearch): array
    {
        return $this->vectors->nearest(VectorQuery::fromPhoto($photoId, $label, $limit, $efSearch, excludeAugmented: false));
    }

    public function replaceGroups(string $label, float $cutoff, array $groups): int
    {
        return DB::transaction(function () use ($label, $cutoff, $groups): int {
            $removed = DB::table('photo_duplicate_groups')
                ->where('label', $label)
                ->whereNotIn('id', $this->reviewedGroups($label))
                ->delete();

            foreach ($groups as $group) {
                $groupId = DB::table('photo_duplicate_groups')->insertGetId([
                    'label' => $label,
                    'cutoff' => $cutoff,
                    'was_split' => $group->split,
                ]);

                DB::table('photo_duplicate_members')->insert(array_map(
                    fn ($member): array => ['group_id' => $groupId, 'photo_id' => $member->photoId, 'max_similarity' => $member->maxSimilarity],
                    $group->members,
                ));
            }

            return $removed;
        });
    }

    /**
     * @return Builder
     */
    private function reviewedGroups(string $label)
    {
        return DB::table('photo_duplicate_reviews')
            ->join('photo_duplicate_groups', 'photo_duplicate_groups.id', '=', 'photo_duplicate_reviews.group_id')
            ->where('photo_duplicate_groups.label', $label)
            ->select('photo_duplicate_reviews.group_id');
    }

    public function deleteGroupsHolding(string $source): void
    {
        DB::transaction(function () use ($source): void {
            $groupIds = DB::table('photo_duplicate_members')
                ->join('listing_photos', 'listing_photos.id', '=', 'photo_duplicate_members.photo_id')
                ->where('listing_photos.source', $source)
                ->distinct()
                ->pluck('photo_duplicate_members.group_id');

            DB::table('listing_photos')
                ->whereIn('id', DB::table('photo_duplicate_members')->whereIn('group_id', $groupIds)->select('photo_id'))
                ->update(['review_status' => null]);
            DB::table('photo_duplicate_groups')->whereIn('id', $groupIds)->delete();
        });
    }
}
