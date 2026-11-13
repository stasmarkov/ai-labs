<?php

namespace Database\Factories;

use App\Models\PendingChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PendingChange>
 */
class PendingChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'mcp',
            'tool' => 'updateTitle',
            'arguments' => ['id' => 1, 'title' => fake()->sentence(3)],
            'preview' => ['listing_id' => 1, 'old_title' => null, 'new_title' => fake()->sentence(3)],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
