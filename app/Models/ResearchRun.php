<?php

namespace App\Models;

use App\Enums\ResearchRunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ResearchRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $topic
 * @property ResearchRunStatus $status
 * @property list<string>|null $sub_questions
 * @property string|null $report
 * @property list<array<string, mixed>>|null $sources
 * @property int $model_calls
 * @property int $steps
 * @property int $failed_notes
 * @property int $dropped_chunk_ids
 * @property int $unknown_citations
 * @property int $tokens_in
 * @property int $tokens_out
 * @property string $provider
 * @property string $model
 * @property array<string, string> $prompt_versions
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ResearchStep> $researchSteps
 */
#[Fillable([
    'topic', 'status', 'sub_questions', 'report', 'sources', 'model_calls', 'steps', 'failed_notes',
    'dropped_chunk_ids', 'unknown_citations', 'tokens_in', 'tokens_out', 'provider', 'model',
    'prompt_versions', 'started_at', 'finished_at', 'error',
])]
class ResearchRun extends Model
{
    /** @use HasFactory<ResearchRunFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ResearchRunStatus::class,
            'sub_questions' => 'array',
            'sources' => 'array',
            'prompt_versions' => 'array',
            'model_calls' => 'integer',
            'steps' => 'integer',
            'failed_notes' => 'integer',
            'dropped_chunk_ids' => 'integer',
            'unknown_citations' => 'integer',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<ResearchStep, $this>
     */
    public function researchSteps(): HasMany
    {
        return $this->hasMany(ResearchStep::class)->orderBy('position');
    }
}
