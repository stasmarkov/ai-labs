<?php

namespace App\Models;

use App\DTOs\ListingFilter;
use App\Enums\Fuel;
use Carbon\CarbonImmutable;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $dealer_id
 * @property string $make
 * @property string $model
 * @property Fuel $fuel
 * @property int $year
 * @property int $price_cents
 * @property string $currency
 * @property int $mileage_km
 * @property string $city
 * @property CarbonImmutable $listed_at
 * @property string|null $title
 * @property-read Dealer $dealer
 * @property-read Collection<int, ListingTag> $tags
 */
#[Fillable(['dealer_id', 'make', 'model', 'fuel', 'year', 'price_cents', 'currency', 'mileage_km', 'city', 'listed_at', 'title'])]
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fuel' => Fuel::class,
            'year' => 'integer',
            'price_cents' => 'integer',
            'mileage_km' => 'integer',
            'listed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Dealer, $this>
     */
    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /**
     * @return HasMany<ListingTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(ListingTag::class);
    }

    /**
     * Every filter field that is set must match; the one definition of the filter for the listings page and the admin tools.
     *
     * @param  Builder<Listing>  $query
     * @return Builder<Listing>
     */
    public function scopeMatching(Builder $query, ListingFilter $filter): Builder
    {
        return $query
            ->when($filter->make !== null, fn (Builder $query) => $query->whereRaw('lower(make) = lower(?)', [$filter->make]))
            ->when($filter->model !== null, fn (Builder $query) => $query->whereRaw('lower(model) = lower(?)', [$filter->model]))
            ->when($filter->city !== null, fn (Builder $query) => $query->whereRaw('lower(city) = lower(?)', [$filter->city]))
            ->when($filter->fuel !== null, fn (Builder $query) => $query->where('fuel', $filter->fuel?->value))
            ->when($filter->minYear !== null, fn (Builder $query) => $query->where('year', '>=', $filter->minYear))
            ->when($filter->maxPriceCents !== null, fn (Builder $query) => $query->where('price_cents', '<=', $filter->maxPriceCents));
    }
}
