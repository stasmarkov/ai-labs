<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\PhotoExtractionRequest;
use App\DTOs\PhotoExtractionStatusCounts;
use App\DTOs\PhotoExtractionTarget;

interface PhotoExtractionRepositoryInterface
{
    /**
     * Photos with no extraction row for the model and prompt version, in ascending id order.
     *
     * @return list<int>
     */
    public function pendingPhotoIds(PhotoExtractionRequest $request, ?int $limit): array;

    /**
     * Delete the rows of the model and prompt version.
     */
    public function deleteFor(PhotoExtractionRequest $request): int;

    /**
     * Create the row as queued, or reset an existing one to queued (attempts, result and errors cleared).
     *
     * @return int the extraction id
     */
    /**
     * Reset or create the row as queued and return its id, or null when it is already queued or running.
     */
    public function queue(int $photoId, PhotoExtractionRequest $request): ?int;

    public function findTarget(int $extractionId): ?PhotoExtractionTarget;

    public function markRunning(int $extractionId): void;

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $invocationIds
     */
    public function complete(int $extractionId, array $data, int $attempts, array $invocationIds, int $latencyMs): void;

    /**
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $invocationIds
     */
    public function fail(int $extractionId, array $errors, int $attempts, array $invocationIds, int $latencyMs): void;

    /**
     * @param  list<int>  $extractionIds
     */
    public function statusCounts(array $extractionIds): PhotoExtractionStatusCounts;

    public function countPhotos(): int;
}
