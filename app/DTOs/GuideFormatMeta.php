<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuideFormatMeta
{
    /**
     * @param  array<string, string>  $covers  The tab that teaches each outcome; only the baseline sets it, the formats derive theirs from their items.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $idea,
        public string $time,
        public array $covers = [],
    ) {}
}
