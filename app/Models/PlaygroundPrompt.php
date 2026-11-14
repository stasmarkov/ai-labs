<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PlaygroundPromptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, PlaygroundPromptVersion> $versions
 */
#[Fillable(['name'])]
class PlaygroundPrompt extends Model
{
    /** @use HasFactory<PlaygroundPromptFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<PlaygroundPromptVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PlaygroundPromptVersion::class)->orderByDesc('version');
    }
}
