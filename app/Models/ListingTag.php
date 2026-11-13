<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ListingTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $listing_id
 * @property string $tag
 * @property CarbonImmutable|null $created_at
 * @property-read Listing $listing
 */
#[Fillable(['listing_id', 'tag'])]
class ListingTag extends Model
{
    /** @use HasFactory<ListingTagFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
