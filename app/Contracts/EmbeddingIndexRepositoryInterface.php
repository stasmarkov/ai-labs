<?php

declare(strict_types=1);

namespace App\Contracts;

interface EmbeddingIndexRepositoryInterface
{
    /**
     * True when some listing has a vector and none of them was embedded with this model; an empty index is false.
     */
    public function listingsEmbeddedOnlyByOtherModels(string $model): bool;

    /**
     * True when some docs chunk has a vector and none of them was embedded with this model; an empty index is false.
     */
    public function docsEmbeddedOnlyByOtherModels(string $model): bool;
}
