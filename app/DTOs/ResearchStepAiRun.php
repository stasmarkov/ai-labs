<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The logged model call behind a research step: what the run page links to and shows.
 */
final readonly class ResearchStepAiRun
{
    public function __construct(
        public string $invocationId,
        public string $model,
        public ?int $tokensIn,
        public ?int $tokensOut,
    ) {}

    /**
     * @return array{invocation_id: string, model: string, tokens_in: int|null, tokens_out: int|null}
     */
    public function toArray(): array
    {
        return [
            'invocation_id' => $this->invocationId,
            'model' => $this->model,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
        ];
    }
}
