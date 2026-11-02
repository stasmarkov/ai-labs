<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property int $position
 * @property string|null $heading
 * @property string $body
 * @property string $content
 * @property int $token_estimate
 * @property bool $oversize
 * @property string $content_hash
 * @property string|null $embedding
 * @property string|null $embedding_model
 */
class DocsChunk extends Model
{
    public const EMBEDDING_INDEX = 'docs_chunks_embedding_hnsw_idx';

    public const TEXT_INDEX = 'docs_chunks_tsv_gin_idx';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'oversize' => 'boolean',
            'embedded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocsDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(DocsDocument::class, 'document_id');
    }
}
