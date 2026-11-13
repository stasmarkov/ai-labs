<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ListingDetail
{
    public function __construct(
        public ListingSummary $listing,
        public DealerSummary $dealer,
        public string $heroImagePath,
        public ?int $photoId = null,
        public ?string $title = null,
        /** @var list<string> */
        public array $tags = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->listing->toArray(),
            'dealer' => $this->dealer->toArray(),
            'hero_image_path' => $this->heroImagePath,
        ];
    }
}
