<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuideBuildStep
{
    public function __construct(
        public string $step,
        public string $detail,
        public ?string $tab = null,
        public ?string $doc = null,
    ) {}
}
