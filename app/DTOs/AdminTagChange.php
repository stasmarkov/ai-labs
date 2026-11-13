<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AdminTagChange
{
    public function __construct(
        public int $id,
        public string $tag,
        public bool $alreadyTagged,
    ) {}
}
