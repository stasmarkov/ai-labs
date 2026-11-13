<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\AdminListingRepositoryInterface;
use App\DTOs\AdminListingDetail;
use App\DTOs\AdminListingPage;
use App\DTOs\AdminListingRow;
use App\DTOs\AdminTagChange;
use App\DTOs\AdminTitleChange;
use App\DTOs\ListingFilter;
use App\Models\Listing;
use App\Models\ListingDocument;
use App\Models\ListingTag;
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class EloquentAdminListingRepository implements AdminListingRepositoryInterface
{
    public function search(ListingFilter $filter, ?string $tag, int $limit): AdminListingPage
    {
        $query = Listing::query()
            ->matching($filter)
            ->when($tag !== null, fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->where('tag', $tag)));

        $total = $query->count();

        $rows = $query
            ->with('tags:id,listing_id,tag')
            ->orderByDesc('listed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Listing $listing): AdminListingRow => new AdminListingRow(
                $listing->id,
                $listing->make,
                $listing->model,
                $listing->year,
                $listing->price_cents,
                $listing->city,
                $listing->title,
                $this->tagNames($listing),
            ))
            ->all();

        return new AdminListingPage($total, $rows);
    }

    public function find(int $id): ?AdminListingDetail
    {
        $listing = Listing::query()->with('tags:id,listing_id,tag')->find($id);

        if ($listing === null) {
            return null;
        }

        $description = ListingDocument::query()->whereKey($id)->value('description');

        return new AdminListingDetail(
            $listing->id,
            $listing->make,
            $listing->model,
            $listing->year,
            $listing->fuel->value,
            $listing->price_cents,
            $listing->currency,
            $listing->mileage_km,
            $listing->city,
            $listing->title,
            $this->tagNames($listing),
            is_string($description) ? $description : null,
        );
    }

    public function countByTag(string $tag): int
    {
        return ListingTag::query()->where('tag', $tag)->count();
    }

    public function updateTitle(int $id, string $title): AdminTitleChange
    {
        $listing = Listing::query()->findOrFail($id);
        $old = $listing->title;
        $changed = $old !== $title;

        if ($changed) {
            $listing->update(['title' => $title]);
        }

        return new AdminTitleChange($listing->id, $old, $title, $changed);
    }

    public function addTag(int $id, string $tag): AdminTagChange
    {
        $listing = Listing::query()->findOrFail($id);

        $inserted = ListingTag::query()->insertOrIgnore([
            'listing_id' => $listing->id,
            'tag' => $tag,
            'created_at' => now(),
        ]);

        return new AdminTagChange($listing->id, $tag, $inserted === 0);
    }

    /**
     * @return list<string>
     */
    private function tagNames(Listing $listing): array
    {
        return $listing->tags->sortBy('id')->pluck('tag')->values()->all();
    }

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback);
    }
}
