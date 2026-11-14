<?php

namespace App\Models;

use App\Enums\ResearchStepPhase;
use App\Enums\ResearchStepStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ResearchStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $research_run_id
 * @property int $position
 * @property ResearchStepPhase $phase
 * @property int|null $sub_question_index
 * @property array<string, mixed> $input
 * @property array<string, mixed>|null $output
 * @property int|null $ai_run_id
 * @property ResearchStepStatus $status
 * @property string|null $error
 * @property int $duration_ms
 * @property CarbonImmutable|null $created_at
 * @property-read ResearchRun $run
 * @property-read AiRun|null $aiRun
 */
#[Fillable([
    'research_run_id', 'position', 'phase', 'sub_question_index', 'input', 'output', 'ai_run_id',
    'status', 'error', 'duration_ms',
])]
class ResearchStep extends Model
{
    /** @use HasFactory<ResearchStepFactory> */
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
            'phase' => ResearchStepPhase::class,
            'status' => ResearchStepStatus::class,
            'sub_question_index' => 'integer',
            'input' => 'array',
            'output' => 'array',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ResearchRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ResearchRun::class, 'research_run_id');
    }

    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }
}
