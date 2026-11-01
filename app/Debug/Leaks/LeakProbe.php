<?php

declare(strict_types=1);

namespace App\Debug\Leaks;

final readonly class LeakProbe
{
    public function __construct(
        public int $dealer,
        public string $locale,
        public LeakMode $mode,
        public int $amountCents,
        public bool $localeGiven = false,
    ) {}
}
