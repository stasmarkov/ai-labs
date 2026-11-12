<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DuplicateEvalOutcome
{
    /**
     * @param  int|null  $bestPhotoId  the validated pick, null when no attempt was valid
     * @param  list<string>  $invocationIds
     */
    public function __construct(
        public int $originalId,
        public int $attempts,
        public ?int $bestPhotoId,
        public int $latencyMs,
        public array $invocationIds = [],
    ) {}

    public function isValid(): bool
    {
        return $this->bestPhotoId !== null;
    }

    public function isValidFirstTime(): bool
    {
        return $this->isValid() && $this->attempts === 1;
    }

    public function pickedOriginal(): bool
    {
        return $this->bestPhotoId === $this->originalId;
    }
}
