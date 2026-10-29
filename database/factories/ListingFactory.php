<?php

namespace Database\Factories;

use App\Enums\Fuel;
use App\Models\Dealer;
use App\Models\Listing;
use Database\Seeders\Catalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $make = fake()->randomElement(array_keys(Catalog::MODELS_BY_MAKE));

        return [
            'dealer_id' => Dealer::factory(),
            'make' => $make,
            'model' => fake()->randomElement(Catalog::MODELS_BY_MAKE[$make]),
            'fuel' => fake()->randomElement(Fuel::cases()),
            'year' => fake()->numberBetween(1980, now()->year + 1),
            'price_cents' => fake()->numberBetween(1_000, 80_000) * 100,
            'currency' => 'EUR',
            'mileage_km' => fake()->numberBetween(0, 300_000),
            'city' => fake()->randomElement(Catalog::CITIES),
            'listed_at' => fake()->dateTimeBetween('-2 years', 'now'),
        ];
    }
}
