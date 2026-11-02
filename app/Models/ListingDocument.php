<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $listing_id
 * @property string $body_type
 * @property string $description
 * @property string|null $embedding
 * @property string|null $embedding_model
 * @property string|null $embedded_text_hash
 */
class ListingDocument extends Model
{
    public const EMBEDDING_INDEX = 'listing_documents_embedding_hnsw_idx';

    protected $primaryKey = 'listing_id';

    public $incrementing = false;

    protected $guarded = [];
}
