<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * One photos:embed dispatch: when it started and how much work it queued.
 */
final readonly class PhotoEmbedRun
{
    public function __construct(
        public string $id,
        public CarbonImmutable $startedAt,
        public int $photos,
        public int $jobs,
    ) {}
}
