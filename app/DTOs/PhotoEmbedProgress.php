<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * How far the batches of a run got. Every photo of a finished batch counts as settled, embedded or not.
 */
final readonly class PhotoEmbedProgress
{
    public function __construct(
        public int $settled,
        public int $failed,
        public int $missing,
    ) {}
}
