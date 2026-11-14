<?php

namespace Database\Factories;

use App\Models\PlaygroundRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaygroundRun>
 */
class PlaygroundRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'playground_prompt_version_id' => null,
            'system_prompt' => fake()->sentence(8),
            'input' => fake()->sentence(6),
            'input_flags' => [],
            'temperature' => 0.2,
            'max_tokens' => 512,
            'version_label' => 'playground:unsaved',
        ];
    }
}
