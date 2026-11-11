<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class SimilarPhotoMatch
{
    public function __construct(
        public int $photoId,
        public int $listingId,
        public string $listingTitle,
        public string $make,
        public string $model,
        public string $path,
        public float $similarity,
    ) {}

    /**
     * @param  array{photo_id: int, listing_id: int, listing_title: string, make: string, model: string, path: string, similarity: float}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['photo_id'], $data['listing_id'], $data['listing_title'], $data['make'], $data['model'], $data['path'], $data['similarity']);
    }

    /**
     * @return array{photo_id: int, listing_id: int, listing_title: string, make: string, model: string, path: string, similarity: float}
     */
    public function toArray(): array
    {
        return [
            'photo_id' => $this->photoId,
            'listing_id' => $this->listingId,
            'listing_title' => $this->listingTitle,
            'make' => $this->make,
            'model' => $this->model,
            'path' => $this->path,
            'similarity' => $this->similarity,
        ];
    }
}
