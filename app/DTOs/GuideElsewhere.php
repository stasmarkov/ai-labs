<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuideElsewhere
{
    public function __construct(
        public string $domain,
        public string $problem,
        public string $how,
    ) {}
}
