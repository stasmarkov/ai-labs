<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One labelled question pair of the cache calibration set: a paraphrase must be served from the cache, a near-miss must not.
 */
final readonly class DocsCachePair
{
    public const PARAPHRASE = 'paraphrase';

    public const NEAR_MISS = 'near_miss';

    public function __construct(
        public string $id,
        public string $label,
        public string $a,
        public string $b,
        public int $line,
    ) {}

    public function isParaphrase(): bool
    {
        return $this->label === self::PARAPHRASE;
    }
}
