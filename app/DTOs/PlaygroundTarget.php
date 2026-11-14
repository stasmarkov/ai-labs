<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One provider and model a playground run calls.
 */
final readonly class PlaygroundTarget
{
    public function __construct(
        public string $provider,
        public string $model,
    ) {}

    /**
     * The pair as one string, which is what makes two targets the same.
     */
    public function key(): string
    {
        return "{$this->provider}:{$this->model}";
    }
}
