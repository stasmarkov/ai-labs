<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $source
 * @property string $path
 * @property string $title
 * @property string $content_hash
 */
class DocsDocument extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['indexed_at' => 'datetime'];
    }

    /**
     * @return HasMany<DocsChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocsChunk::class, 'document_id');
    }
}
