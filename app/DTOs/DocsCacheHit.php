<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\DocsCacheKind;

final readonly class DocsCacheHit
{
    public function __construct(
        public DocsCacheEntry $entry,
        public DocsCacheKind $kind,
        public float $similarity,
    ) {}
}
