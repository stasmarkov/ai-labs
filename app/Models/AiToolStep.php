<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ai_run_id
 * @property int $position
 * @property string $tool
 * @property array<string, mixed> $arguments
 * @property string $outcome
 * @property string|null $error
 * @property array<string, mixed>|null $result_summary
 * @property int $duration_ms
 * @property CarbonImmutable|null $created_at
 * @property-read AiRun $run
 */
#[Fillable(['ai_run_id', 'position', 'tool', 'arguments', 'outcome', 'error', 'result_summary', 'duration_ms'])]
class AiToolStep extends Model
{
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
            'arguments' => 'array',
            'result_summary' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }
}
