<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\SimilarPhotoMatch;

interface SimilarPhotosRepositoryInterface
{
    /**
     * The model label stored with the photo's vector; null when the photo does not exist or has no vector.
     */
    public function embeddingModel(int $photoId): ?string;

    /**
     * The listing the photo belongs to; null when the photo does not exist.
     */
    public function listingId(int $photoId): ?int;

    /**
     * The photos nearest to the given photo's vector by cosine distance (the photo itself first), among vectors made
     * with the model label, ties by photo id ascending. The listing's year, make and model become the title.
     *
     * @return list<SimilarPhotoMatch>
     */
    public function nearest(int $photoId, string $model, int $limit, int $efSearch): array;
}
