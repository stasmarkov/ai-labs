<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ListingDocumentText;
use App\DTOs\ListingEmbedding;
use App\DTOs\ListingFacts;
use Carbon\CarbonImmutable;

interface ListingDocumentRepositoryInterface
{
    /**
     * @return list<ListingFacts>
     */
    public function listingsAfter(int $afterId, int $limit): array;

    /**
     * Insert or update documents; rows whose text is unchanged are left untouched.
     *
     * @param  list<ListingDocumentText>  $documents
     * @return int number of rows inserted or changed
     */
    public function upsertTexts(array $documents): int;

    public function countListingsWithoutDocument(): int;

    /**
     * Clear the vectors of the first $limit documents by listing id (all when null).
     *
     * @return int number of documents cleared
     */
    public function clearEmbeddings(?int $limit): int;

    /**
     * Ids, in ascending order, of documents among the first $limit by listing id (all when null)
     * whose vector is missing or was made from other text or by another model.
     *
     * @return list<int>
     */
    public function staleListingIds(string $model, ?int $limit): array;

    /**
     * The given documents that still need a vector for this model.
     *
     * @param  list<int>  $listingIds
     * @return list<ListingDocumentText>
     */
    public function staleDocuments(array $listingIds, string $model): array;

    /**
     * Store all vectors in one statement; a row whose text changed since it was embedded is skipped.
     *
     * @param  list<ListingEmbedding>  $embeddings
     * @return int number of rows stored
     */
    public function storeEmbeddings(array $embeddings, string $model, CarbonImmutable $embeddedAt): int;

    public function countEmbeddedSince(CarbonImmutable $since): int;

    public function lastEmbeddedAt(CarbonImmutable $since): ?CarbonImmutable;
}
