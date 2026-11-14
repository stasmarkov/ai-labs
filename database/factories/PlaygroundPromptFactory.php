<?php

namespace Database\Factories;

use App\Models\PlaygroundPrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaygroundPrompt>
 */
class PlaygroundPromptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
        ];
    }
}
