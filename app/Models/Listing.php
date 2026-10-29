<?php

namespace App\Models;

use App\Enums\Fuel;
use Carbon\CarbonImmutable;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property-read Dealer $dealer
 */
#[Fillable(['dealer_id', 'make', 'model', 'fuel', 'year', 'price_cents', 'currency', 'mileage_km', 'city', 'listed_at'])]
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
}
