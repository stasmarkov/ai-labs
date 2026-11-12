<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoLabItem
{
    public function __construct(
        public int $id,
        public string $url,
        public string $title,
        public ?string $status,
        public ?string $variant = null,
    ) {}

    /**
     * @return array{id: int, url: string, title: string, status: string|null, variant: string|null}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'url' => $this->url, 'title' => $this->title, 'status' => $this->status, 'variant' => $this->variant];
    }
}
