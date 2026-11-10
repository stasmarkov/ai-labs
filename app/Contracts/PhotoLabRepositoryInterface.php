<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\PhotoExtractionRequest;
use App\DTOs\PhotoLabDetail;
use App\DTOs\PhotoLabItem;
use Illuminate\Pagination\LengthAwarePaginator;

interface PhotoLabRepositoryInterface
{
    /**
     * The photos in ascending id order, each with the status of its extraction for the model and prompt version.
     *
     * @return LengthAwarePaginator<int, PhotoLabItem>
     */
    public function paginate(PhotoExtractionRequest $request, int $page, int $perPage): LengthAwarePaginator;

    public function find(int $photoId, PhotoExtractionRequest $request): ?PhotoLabDetail;

    /**
     * The id of the first photo, or null when there are none.
     */
    public function firstId(): ?int;
}
