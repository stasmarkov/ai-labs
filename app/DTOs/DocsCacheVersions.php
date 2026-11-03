<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The four versions an entry must carry to be served.
 */
final readonly class DocsCacheVersions
{
    public function __construct(
        public string $promptVersion,
        public string $answerModel,
        public string $embeddingModel,
        public string $indexVersion,
    ) {}
}
