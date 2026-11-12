<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\EmbeddingIndexCount;
use App\DTOs\NewListingPhoto;
use App\DTOs\PhotoCaptionRow;
use App\DTOs\PhotoEmbedding;
use App\DTOs\PhotoExtractionRequest;
use App\DTOs\PhotoListingCandidate;
use App\DTOs\PhotoTextEmbedding;
use App\DTOs\PhotoToEmbed;
use Carbon\CarbonImmutable;
use Closure;

interface ListingPhotoRepositoryInterface
{
    /**
     * Listings of the make and model, in ascending id order.
     *
     * @return list<PhotoListingCandidate>
     */
    public function candidatesForModel(string $make, string $model): array;

    /**
     * @return list<int>
     */
    public function listingIdsWithPhoto(): array;

    public function countListingsWithPhoto(): int;

    public function hasSha256(string $sha256): bool;

    /**
     * Insert the row and run the writer with its public-disk path in one transaction; a writer that throws rolls the row back.
     *
     * @param  Closure(string): void  $writeFile
     * @return string the stored path
     */
    public function store(NewListingPhoto $photo, Closure $writeFile): string;

    /**
     * Delete the rows of a source.
     *
     * @return list<string> the paths of the deleted rows
     */
    public function deleteBySource(string $source): array;

    /**
     * Photos, photos with a vector, and photos whose vector was made with the given model label.
     */
    public function embeddingCount(string $model): EmbeddingIndexCount;

    /**
     * Remove the vectors of the first $limit photos by id (every photo with a null limit), so they are stale again.
     */
    public function clearEmbeddings(?int $limit): int;

    /**
     * Ids, in ascending order, of the photos without a vector or with one made by another model label, among the
     * first $limit photos by id.
     *
     * @return list<int>
     */
    public function staleEmbeddingPhotoIds(string $model, ?int $limit): array;

    /**
     * The stale photos among the given ids.
     *
     * @param  list<int>  $photoIds
     * @return list<PhotoToEmbed>
     */
    public function stalePhotos(array $photoIds, string $model): array;

    /**
     * @param  list<PhotoEmbedding>  $embeddings
     * @return int the number of rows stored
     */
    public function storeEmbeddings(array $embeddings, string $model, CarbonImmutable $embeddedAt): int;

    public function countEmbeddedSince(CarbonImmutable $since): int;

    /**
     * The first $limit photos by id (every photo with a null limit) with the caption of the completed extraction of the
     * given model and prompt version, in ascending id order.
     *
     * @return list<PhotoCaptionRow>
     */
    public function captionRows(PhotoExtractionRequest $request, ?int $limit): array;

    /**
     * The same rows for the given photo ids.
     *
     * @param  list<int>  $photoIds
     * @return list<PhotoCaptionRow>
     */
    public function captionRowsFor(PhotoExtractionRequest $request, array $photoIds): array;

    /**
     * Remove the text vectors of the first $limit photos by id (every photo with a null limit).
     */
    public function clearTextEmbeddingsInScope(?int $limit): int;

    /**
     * Remove the text vectors of the given photos.
     *
     * @param  list<int>  $photoIds
     */
    public function clearTextEmbeddings(array $photoIds): int;

    /**
     * Store caption vectors with their label and hash.
     *
     * @param  list<PhotoTextEmbedding>  $embeddings
     * @return int the number of rows stored
     */
    public function storeTextEmbeddings(array $embeddings, string $model, CarbonImmutable $embeddedAt): int;

    public function countTextEmbeddedSince(CarbonImmutable $since): int;
}
