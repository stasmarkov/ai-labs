<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\McpToolCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $tool
 * @property array<string, mixed> $arguments
 * @property array<string, mixed>|null $result_summary
 * @property string $outcome
 * @property string|null $error
 * @property int $duration_ms
 * @property string|null $pending_change_id
 * @property CarbonImmutable|null $created_at
 * @property-read PendingChange|null $pendingChange
 */
#[Fillable(['tool', 'arguments', 'result_summary', 'outcome', 'error', 'duration_ms', 'pending_change_id'])]
class McpToolCall extends Model
{
    /** @use HasFactory<McpToolCallFactory> */
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
            'arguments' => 'array',
            'result_summary' => 'array',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PendingChange, $this>
     */
    public function pendingChange(): BelongsTo
    {
        return $this->belongsTo(PendingChange::class);
    }
}
