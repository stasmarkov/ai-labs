<?php

namespace Database\Factories;

use App\Models\ListingPhoto;
use App\Models\PhotoExtraction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhotoExtraction>
 */
class PhotoExtractionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'photo_id' => ListingPhoto::factory(),
            'provider' => 'ollama',
            'model' => 'gemma4:12b',
            'prompt_version' => 'photo-vision@v1',
            'status' => PhotoExtraction::STATUS_QUEUED,
            'attempts' => 0,
        ];
    }
}
