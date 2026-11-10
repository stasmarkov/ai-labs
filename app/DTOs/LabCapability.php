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
        public ?string $guide = null,
        public ?string $code = null,
    ) {}

    /**
     * @return array{key: string, title: string, route: string, status: string, slice: string, guide: string|null, code: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'route' => $this->route,
            'status' => $this->status,
            'slice' => $this->slice,
            'guide' => $this->guide,
            'code' => $this->code,
        ];
    }
}
