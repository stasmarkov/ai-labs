<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ListingPhotoFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $listing_id
 * @property string $path
 * @property string $source
 * @property string $source_class
 * @property string $source_file
 * @property int $width
 * @property int $height
 * @property string $sha256
 * @property string|null $embedding
 * @property string|null $embedding_model
 * @property CarbonImmutable|null $embedded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Listing $listing
 * @property-read Collection<int, PhotoExtraction> $extractions
 */
class ListingPhoto extends Model
{
    /** @use HasFactory<ListingPhotoFactory> */
    use HasFactory;

    public const SOURCE_STANFORD_CARS = 'stanford-cars';

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /** @var list<string> the vector never travels to a page or a JSON response */
    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return [
            'listing_id' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'embedded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return HasMany<PhotoExtraction, $this>
     */
    public function extractions(): HasMany
    {
        return $this->hasMany(PhotoExtraction::class, 'photo_id');
    }
}
