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
        public ?HybridParts $parts = null,
    ) {}

    /**
     * @param  array{photo_id: int, listing_id: int, listing_title: string, make: string, model: string, path: string, similarity: float, parts?: array{image: float, caption: float|null, image_only: bool}}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['photo_id'],
            $data['listing_id'],
            $data['listing_title'],
            $data['make'],
            $data['model'],
            $data['path'],
            $data['similarity'],
            isset($data['parts']) ? HybridParts::fromArray($data['parts']) : null,
        );
    }

    /**
     * @return array{photo_id: int, listing_id: int, listing_title: string, make: string, model: string, path: string, similarity: float, parts?: array{image: float, caption: float|null, image_only: bool}}
     */
    public function toArray(): array
    {
        $data = [
            'photo_id' => $this->photoId,
            'listing_id' => $this->listingId,
            'listing_title' => $this->listingTitle,
            'make' => $this->make,
            'model' => $this->model,
            'path' => $this->path,
            'similarity' => $this->similarity,
        ];

        if ($this->parts !== null) {
            $data['parts'] = $this->parts->toArray();
        }

        return $data;
    }
}
