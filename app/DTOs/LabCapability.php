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
        public string $summary,
        public string $guideKey,
    ) {}

    /**
     * @return array{key: string, title: string, route: string, status: string, summary: string, guideKey: string, guideHref: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'route' => $this->route,
            'status' => $this->status,
            'summary' => $this->summary,
            'guideKey' => $this->guideKey,
            'guideHref' => route('ai.guides.show', ['key' => $this->guideKey], false),
        ];
    }
}
