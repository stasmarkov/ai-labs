<?php

namespace Database\Factories;

use App\Enums\ResearchRunStatus;
use App\Models\ResearchRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResearchRun>
 */
class ResearchRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'topic' => fake()->sentence(4),
            'status' => ResearchRunStatus::Running,
            'provider' => 'ollama',
            'model' => 'qwen3.5:9b',
            'prompt_versions' => ['planner' => 'v1', 'note' => 'v1', 'report' => 'v1'],
            'started_at' => now(),
        ];
    }

    public function done(): static
    {
        return $this->state(fn (): array => [
            'status' => ResearchRunStatus::Done,
            'report' => 'A short report [1].',
            'finished_at' => now(),
        ]);
    }
}
