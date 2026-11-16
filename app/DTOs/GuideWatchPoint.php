<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuideWatchPoint
{
    public function __construct(
        public string $point,
        public string $detail,
        public string $tab,
    ) {}

    /**
     * @return array{point: string, detail: string, tab: string}
     */
    public function toArray(): array
    {
        return [
            'point' => $this->point,
            'detail' => $this->detail,
            'tab' => $this->tab,
        ];
    }
}
