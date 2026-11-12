<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The parts of a hybrid score: the image and caption similarities, and whether the photo fell back to the image alone.
 */
final readonly class HybridParts
{
    public function __construct(
        public float $image,
        public ?float $caption,
        public bool $imageOnly,
    ) {}

    /**
     * @param  array{image: float, caption: float|null, image_only: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['image'], $data['caption'], $data['image_only']);
    }

    /**
     * @return array{image: float, caption: float|null, image_only: bool}
     */
    public function toArray(): array
    {
        return ['image' => $this->image, 'caption' => $this->caption, 'image_only' => $this->imageOnly];
    }
}
