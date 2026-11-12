<?php

namespace Database\Factories;

use App\Models\PhotoDuplicateJudgement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhotoDuplicateJudgement>
 */
class PhotoDuplicateJudgementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => null,
            'provider' => 'ollama',
            'model' => 'gemma4:12b',
            'prompt_version' => 'photo-best-pick@v1',
            'status' => PhotoDuplicateJudgement::STATUS_QUEUED,
            'attempts' => 0,
        ];
    }
}
