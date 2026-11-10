<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoEvalOutcome
{
    /**
     * @param  array<string, mixed>|null  $data  the validated answer, null when no attempt was valid
     * @param  list<int>  $attemptLatenciesMs
     * @param  list<string>  $invocationIds
     * @param  string|null  $expectedBodyType  the catalog body type of the photo's class, null when the class body has no mapping
     */
    public function __construct(
        public int $photoId,
        public int $attempts,
        public ?array $data,
        public int $latencyMs,
        public array $attemptLatenciesMs,
        public ?string $expectedBodyType,
        public array $invocationIds = [],
    ) {}

    public function isValid(): bool
    {
        return $this->data !== null;
    }

    public function isValidFirstTime(): bool
    {
        return $this->isValid() && $this->attempts === 1;
    }
}
