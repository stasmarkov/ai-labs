<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoTextEmbedding
{
    /**
     * @param  list<float>  $vector  L2-normalised
     * @param  string  $hash  sha256 of the encoded text
     */
    public function __construct(
        public int $photoId,
        public array $vector,
        public string $hash,
    ) {}
}
