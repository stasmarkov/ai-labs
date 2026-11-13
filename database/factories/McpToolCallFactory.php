<?php

namespace Database\Factories;

use App\Models\McpToolCall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpToolCall>
 */
class McpToolCallFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tool' => 'search_listings',
            'arguments' => ['query' => fake()->word()],
            'result_summary' => ['total' => fake()->numberBetween(0, 20)],
            'outcome' => 'ok',
            'duration_ms' => fake()->numberBetween(1, 50),
        ];
    }
}
