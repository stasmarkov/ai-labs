<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LabCapability
{
    public function __construct(
        public string $key,
        public string $title,
        public string $route,
        public string $status,
        public string $slice,
    ) {}

    /**
     * @return array{key: string, title: string, route: string, status: string, slice: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'route' => $this->route,
            'status' => $this->status,
            'slice' => $this->slice,
        ];
    }
}
