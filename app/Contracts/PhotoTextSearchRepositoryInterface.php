<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\SimilarPhotoMatch;

interface PhotoTextSearchRepositoryInterface
{
    /**
     * Whether any photo holds a vector made with the model label.
     */
    public function hasVectors(string $model): bool;

    /**
     * The photos nearest to a text vector by cosine distance, among vectors made with the model label, ties by photo
     * id ascending. The listing's year, make and model become the title; the vector is the only query input.
     *
     * @param  list<float>  $vector
     * @return list<SimilarPhotoMatch>
     */
    public function nearestToVector(array $vector, string $model, int $limit, int $efSearch): array;
}
