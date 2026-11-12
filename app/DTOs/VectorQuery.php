<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A nearest-photos query: the reference is either a stored photo's vector or a literal vector.
 */
final readonly class VectorQuery
{
    /**
     * @param  list<float>|null  $vector
     */
    private function __construct(
        public ?int $photoId,
        public ?array $vector,
        public string $model,
        public int $limit,
        public int $efSearch,
    ) {}

    public static function fromPhoto(int $photoId, string $model, int $limit, int $efSearch): self
    {
        return new self($photoId, null, $model, $limit, $efSearch);
    }

    /**
     * @param  list<float>  $vector
     */
    public static function fromVector(array $vector, string $model, int $limit, int $efSearch): self
    {
        return new self(null, $vector, $model, $limit, $efSearch);
    }
}
