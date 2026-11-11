<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A photo that still needs a vector: its id and its public-disk path.
 */
final readonly class PhotoToEmbed
{
    public function __construct(
        public int $id,
        public string $path,
    ) {}
}
