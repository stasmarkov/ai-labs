<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\EmbeddingIndexRepositoryInterface;
use Illuminate\Support\Facades\DB;

final readonly class EloquentEmbeddingIndexRepository implements EmbeddingIndexRepositoryInterface
{
    public function listingsEmbeddedOnlyByOtherModels(string $model): bool
    {
        return $this->embeddedOnlyByOthers('listing_documents', $model);
    }

    public function docsEmbeddedOnlyByOtherModels(string $model): bool
    {
        return $this->embeddedOnlyByOthers('docs_chunks', $model);
    }

    private function embeddedOnlyByOthers(string $table, string $model): bool
    {
        $embedded = DB::table($table)->whereNotNull('embedding');

        return (clone $embedded)->exists() && ! $embedded->where('embedding_model', $model)->exists();
    }
}
