<?php

namespace Database\Factories;

use App\Models\PlaygroundPrompt;
use App\Models\PlaygroundPromptVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaygroundPromptVersion>
 */
class PlaygroundPromptVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'playground_prompt_id' => PlaygroundPrompt::factory(),
            'version' => 1,
            'system_prompt' => fake()->sentence(8),
            'note' => null,
            'temperature' => 0.2,
            'max_tokens' => 512,
        ];
    }
}
