<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PendingChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $source
 * @property string $tool
 * @property array<string, mixed> $arguments
 * @property array<string, mixed> $preview
 * @property string $status
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, McpToolCall> $toolCalls
 */
#[Fillable(['source', 'tool', 'arguments', 'preview', 'status', 'result', 'error', 'expires_at', 'decided_at'])]
class PendingChange extends Model
{
    /** @use HasFactory<PendingChangeFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'preview' => 'array',
            'result' => 'array',
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<McpToolCall, $this>
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(McpToolCall::class);
    }
}
