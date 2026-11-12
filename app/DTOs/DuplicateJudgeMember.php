<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A stored photo of a group to judge: where its file is and its size, so the call can be bounded.
 */
final readonly class DuplicateJudgeMember
{
    public function __construct(
        public int $photoId,
        public string $path,
        public int $width,
        public int $height,
    ) {}
}
