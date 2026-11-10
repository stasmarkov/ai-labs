<?php

namespace Database\Factories;

use App\Models\Listing;
use App\Models\ListingPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingPhoto>
 */
class ListingPhotoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $file = fake()->unique()->numerify('#####').'.jpg';

        return [
            'listing_id' => Listing::factory(),
            'path' => 'photos/'.fake()->unique()->numerify('######').'/'.$file,
            'source' => ListingPhoto::SOURCE_STANFORD_CARS,
            'source_class' => 'Ford Fiesta Sedan 2012',
            'source_file' => $file,
            'width' => 1024,
            'height' => 768,
            'sha256' => hash('sha256', $file),
        ];
    }
}
