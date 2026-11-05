<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ListingPriceWatch
{
    public function __construct(
        public string $topic,
        public ?string $privateTopic = null,
        public ?string $subscriberToken = null,
        public ?int $subscriberTokenExpiresAt = null,
    ) {}
}
