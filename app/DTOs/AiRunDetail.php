<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AiRunDetail
{
    public function __construct(
        public AiRunSummary $summary,
        public ?string $promptVersion,
        public ?string $errorMessage,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            ...$this->summary->toArray(),
            'prompt_version' => $this->promptVersion,
            'error_message' => $this->errorMessage,
        ];
    }
}
