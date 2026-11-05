<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The outcome of a reprice. A publish flag is null when no publish was attempted
 * (mercure disabled, the public price unchanged, or the private update not requested).
 */
final readonly class ListingRepriceResult
{
    public function __construct(
        public ListingPriceUpdate $update,
        public ?bool $publicPublished,
        public ?bool $privatePublished,
    ) {}
}
