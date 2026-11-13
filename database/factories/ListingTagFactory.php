<?php

namespace Database\Factories;

use App\Models\Listing;
use App\Models\ListingTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingTag>
 */
class ListingTagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'tag' => fake()->unique()->lexify('tag-?????'),
        ];
    }
}
