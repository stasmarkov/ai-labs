<?php

namespace Database\Factories;

use App\Models\Dealer;
use Database\Seeders\Catalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dealer>
 */
class DealerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'city' => fake()->randomElement(Catalog::CITIES),
        ];
    }
}
