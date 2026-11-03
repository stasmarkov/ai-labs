<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ChunkEmbedding;
use App\DTOs\ChunkText;
use App\DTOs\IndexedDocument;
use Carbon\CarbonImmutable;

interface DocsIndexRepositoryInterface
{
    /**
     * Content hash of every stored document of a source, keyed by path.
     *
     * @return array<string, string>
     */
    public function documentHashes(string $source): array;

    /**
     * One "source|path|hash" line per stored document, ordered by source then path.
     *
     * @return list<string>
     */
    public function documentVersionLines(): array;

    /**
     * Replace the chunks of one document in a transaction. A new chunk takes the vector of an
     * old chunk of the same document with the same text hash and the same model.
     *
     * @return int number of chunks stored
     */
    public function replaceDocument(IndexedDocument $document, string $model, CarbonImmutable $indexedAt): int;

    /**
     * Delete the documents of a source whose path is not in $paths; their chunks go with them.
     *
     * @param  list<string>  $paths
     * @return int number of documents deleted
     */
    public function deleteMissing(string $source, array $paths): int;

    /**
     * Ids, ascending, of chunks that have no vector for this model.
     *
     * @return list<int>
     */
    public function staleChunkIds(string $model): array;

    /**
     * How many chunks still have no vector for this model.
     */
    public function pendingEmbeddingCount(string $model): int;

    /**
     * The given chunks that still have no vector for this model.
     *
     * @param  list<int>  $chunkIds
     * @return list<ChunkText>
     */
    public function staleChunks(array $chunkIds, string $model): array;

    /**
     * Store all vectors in one statement; a chunk whose text changed since it was embedded is skipped.
     *
     * @param  list<ChunkEmbedding>  $embeddings
     * @return int number of chunks stored
     */
    public function storeEmbeddings(array $embeddings, string $model, CarbonImmutable $embeddedAt): int;
}
