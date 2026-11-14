<?php

namespace App\Models;

use App\Enums\PlaygroundResultStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PlaygroundResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $playground_run_id
 * @property int $position
 * @property string $provider
 * @property string $model
 * @property PlaygroundResultStatus $status
 * @property string|null $output
 * @property string|null $error
 * @property int|null $tokens_in
 * @property int|null $tokens_out
 * @property int|null $ttft_ms
 * @property int $duration_ms
 * @property string|null $ai_run_invocation_id
 * @property CarbonImmutable|null $created_at
 * @property-read PlaygroundRun $run
 */
#[Fillable([
    'playground_run_id', 'position', 'provider', 'model', 'status', 'output', 'error', 'tokens_in', 'tokens_out',
    'ttft_ms', 'duration_ms', 'ai_run_invocation_id',
])]
class PlaygroundResult extends Model
{
    /** @use HasFactory<PlaygroundResultFactory> */
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
            'position' => 'integer',
            'status' => PlaygroundResultStatus::class,
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'ttft_ms' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PlaygroundRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PlaygroundRun::class, 'playground_run_id');
    }
}
