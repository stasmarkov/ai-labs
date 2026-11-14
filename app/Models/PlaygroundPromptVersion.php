<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PlaygroundPromptVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $playground_prompt_id
 * @property int $version
 * @property string $system_prompt
 * @property string|null $note
 * @property float $temperature
 * @property int $max_tokens
 * @property CarbonImmutable|null $created_at
 * @property-read PlaygroundPrompt $prompt
 */
#[Fillable(['playground_prompt_id', 'version', 'system_prompt', 'note', 'temperature', 'max_tokens'])]
class PlaygroundPromptVersion extends Model
{
    /** @use HasFactory<PlaygroundPromptVersionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'temperature' => 'float',
            'max_tokens' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PlaygroundPrompt, $this>
     */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(PlaygroundPrompt::class, 'playground_prompt_id');
    }
}
