<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\NewListingPhoto;
use App\DTOs\PhotoListingCandidate;
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
}
