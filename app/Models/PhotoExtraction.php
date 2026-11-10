<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PhotoExtractionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $photo_id
 * @property string $provider
 * @property string $model
 * @property string $prompt_version
 * @property string $status
 * @property int $attempts
 * @property array<string, mixed>|null $data
 * @property array<string, list<string>>|null $errors
 * @property list<string>|null $invocation_ids
 * @property int|null $latency_ms
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ListingPhoto $photo
 */
class PhotoExtraction extends Model
{
    /** @use HasFactory<PhotoExtractionFactory> */
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
            'photo_id' => 'integer',
            'attempts' => 'integer',
            'data' => 'array',
            'errors' => 'array',
            'invocation_ids' => 'array',
            'latency_ms' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ListingPhoto, $this>
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(ListingPhoto::class, 'photo_id');
    }
}
