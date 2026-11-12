<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PhotoDuplicateJudgementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $group_id
 * @property string $provider
 * @property string $model
 * @property string $prompt_version
 * @property string $status
 * @property int $attempts
 * @property int|null $best_photo_id
 * @property list<string>|null $reasons
 * @property list<array{photo_id: int, problem: string}>|null $issues
 * @property array<string, list<string>>|null $errors
 * @property list<string>|null $invocation_ids
 * @property int|null $latency_ms
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class PhotoDuplicateJudgement extends Model
{
    /** @use HasFactory<PhotoDuplicateJudgementFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'attempts' => 'integer',
            'best_photo_id' => 'integer',
            'reasons' => 'array',
            'issues' => 'array',
            'errors' => 'array',
            'invocation_ids' => 'array',
            'latency_ms' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
