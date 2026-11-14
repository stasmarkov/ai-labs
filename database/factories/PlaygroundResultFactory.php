<?php

namespace Database\Factories;

use App\Enums\PlaygroundResultStatus;
use App\Models\PlaygroundResult;
use App\Models\PlaygroundRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaygroundResult>
 */
class PlaygroundResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'playground_run_id' => PlaygroundRun::factory(),
            'position' => 1,
            'provider' => 'ollama',
            'model' => 'qwen3.5:9b',
            'status' => PlaygroundResultStatus::Ok,
            'output' => fake()->sentence(10),
            'tokens_in' => 40,
            'tokens_out' => 20,
            'ttft_ms' => null,
            'duration_ms' => fake()->numberBetween(100, 2000),
            'ai_run_invocation_id' => (string) fake()->uuid(),
        ];
    }
}
