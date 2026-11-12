<?php

declare(strict_types=1);

namespace App\Contracts;

interface CaptionSimilarityRepositoryInterface
{
    /**
     * The cosine similarity between the reference photo's caption vector and the caption vectors of the given photos,
     * keyed by photo id. A photo without a caption vector of this model, or a reference without one, is left out.
     *
     * @param  list<int>  $photoIds
     * @return array<int, float>
     */
    public function captionSimilarities(int $referencePhotoId, array $photoIds, string $model): array;

    /**
     * Whether the photo has a caption vector of this model.
     */
    public function hasCaptionVector(int $photoId, string $model): bool;
}
