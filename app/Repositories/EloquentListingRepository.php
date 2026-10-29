<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\ListingRepositoryInterface;
use App\DTOs\DealerSummary;
use App\DTOs\ListingDetail;
use App\DTOs\ListingFilter;
use App\DTOs\ListingPage;
use App\DTOs\ListingSummary;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentListingRepository implements ListingRepositoryInterface
{
    private const HERO_VARIANTS = 6;

    private const SUMMARY_COLUMNS = [
        'id', 'dealer_id', 'make', 'model', 'fuel', 'year', 'price_cents', 'currency', 'mileage_km', 'city', 'listed_at',
    ];

    public function search(ListingFilter $filter): ListingPage
    {
        $query = $this->filtered($filter);

        $total = $query->count();

        $items = $query
            ->select(self::SUMMARY_COLUMNS)
            ->orderByDesc('listed_at')
            ->orderByDesc('id')
            ->forPage($filter->page, $filter->perPage)
            ->get()
            ->map(fn (Listing $listing): ListingSummary => $this->toSummary($listing))
            ->all();

        return new ListingPage($items, $total, $filter->page, $filter->perPage);
    }

    public function findDetail(int $id): ListingDetail
    {
        $listing = Listing::query()->with('dealer')->findOrFail($id);

        return new ListingDetail(
            $this->toSummary($listing),
            new DealerSummary($listing->dealer->id, $listing->dealer->name, $listing->dealer->city),
            '/images/heroes/hero-'.($listing->id % self::HERO_VARIANTS).'.webp',
        );
    }

    /**
     * @return Builder<Listing>
     */
    private function filtered(ListingFilter $filter): Builder
    {
        return Listing::query()
            ->when($filter->make !== null, fn (Builder $query) => $query->whereRaw('lower(make) = lower(?)', [$filter->make]))
            ->when($filter->model !== null, fn (Builder $query) => $query->whereRaw('lower(model) = lower(?)', [$filter->model]))
            ->when($filter->city !== null, fn (Builder $query) => $query->whereRaw('lower(city) = lower(?)', [$filter->city]))
            ->when($filter->fuel !== null, fn (Builder $query) => $query->where('fuel', $filter->fuel?->value))
            ->when($filter->minYear !== null, fn (Builder $query) => $query->where('year', '>=', $filter->minYear))
            ->when($filter->maxPriceCents !== null, fn (Builder $query) => $query->where('price_cents', '<=', $filter->maxPriceCents));
    }

    private function toSummary(Listing $listing): ListingSummary
    {
        return new ListingSummary(
            $listing->id,
            $listing->make,
            $listing->model,
            $listing->year,
            $listing->fuel->value,
            $listing->mileage_km,
            $listing->price_cents,
            $listing->currency,
            $listing->city,
            $listing->listed_at->utc()->format('Y-m-d\TH:i:s\Z'),
            $listing->dealer_id,
        );
    }
}
