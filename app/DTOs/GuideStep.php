<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuideStep
{
    public function __construct(
        public string $title,
        public string $body,
        public ?string $event = null,
        public ?string $snippet = null,
    ) {}
}
