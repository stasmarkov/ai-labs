<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PlaygroundRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int|null $playground_prompt_version_id
 * @property string $system_prompt
 * @property string $input
 * @property list<string> $input_flags
 * @property float $temperature
 * @property int $max_tokens
 * @property string $version_label
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PlaygroundPromptVersion|null $promptVersion
 * @property-read Collection<int, PlaygroundResult> $results
 */
#[Fillable([
    'playground_prompt_version_id', 'system_prompt', 'input', 'input_flags', 'temperature', 'max_tokens', 'version_label',
])]
class PlaygroundRun extends Model
{
    /** @use HasFactory<PlaygroundRunFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_flags' => 'array',
            'temperature' => 'float',
            'max_tokens' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PlaygroundPromptVersion, $this>
     */
    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PlaygroundPromptVersion::class, 'playground_prompt_version_id');
    }

    /**
     * @return HasMany<PlaygroundResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(PlaygroundResult::class)->orderBy('position');
    }
}
