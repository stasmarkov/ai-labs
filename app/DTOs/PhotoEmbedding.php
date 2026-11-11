<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoEmbedding
{
    /**
     * @param  list<float>  $vector  L2-normalised
     */
    public function __construct(
        public int $photoId,
        public array $vector,
    ) {}
}
