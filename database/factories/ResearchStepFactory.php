<?php

namespace Database\Factories;

use App\Enums\ResearchStepPhase;
use App\Enums\ResearchStepStatus;
use App\Models\ResearchRun;
use App\Models\ResearchStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResearchStep>
 */
class ResearchStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'research_run_id' => ResearchRun::factory(),
            'position' => 1,
            'phase' => ResearchStepPhase::Plan,
            'input' => ['topic' => fake()->sentence(3)],
            'output' => ['sub_questions' => [fake()->sentence(5), fake()->sentence(5)]],
            'status' => ResearchStepStatus::Ok,
            'duration_ms' => fake()->numberBetween(1, 500),
        ];
    }
}
