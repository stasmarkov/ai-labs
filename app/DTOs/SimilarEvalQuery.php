<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class SimilarEvalQuery
{
    /**
     * @param  list<SimilarPhotoMatch>  $results  best first
     */
    public function __construct(
        public string $make,
        public string $model,
        public array $results,
    ) {}
}
