<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RagEvalRepositoryInterface;
use App\DTOs\RagChunkSettings;
use App\Exceptions\RagEvalIndexException;

/**
 * Checks the docs index is ready to be measured and reports the chunk sizes it was built with.
 */
final readonly class RagEvalIndexInspector
{
    public function __construct(
        private RagEvalRepositoryInterface $repository,
        private DocsEmbeddingService $embedding,
    ) {}

    /**
     * @throws RagEvalIndexException when no chunk has a vector, or some chunk has none.
     */
    public function inspect(): RagChunkSettings
    {
        $counts = $this->repository->chunkCounts($this->embedding->modelLabel());

        if ($counts['embedded'] === 0) {
            throw RagEvalIndexException::noChunks();
        }

        if ($counts['embedded'] < $counts['total']) {
            throw RagEvalIndexException::notFullyEmbedded($counts['embedded'], $counts['total']);
        }

        return new RagChunkSettings($this->repository->chunkSettings());
    }
}
