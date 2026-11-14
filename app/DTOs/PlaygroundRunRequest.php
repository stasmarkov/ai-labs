<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * What a playground run asks for: one prompt source (a saved version or a text), the input, the targets and the optional
 * settings. A setting that is null takes the version's value, or the default for a text.
 */
final readonly class PlaygroundRunRequest
{
    /**
     * @param  list<PlaygroundTarget>  $targets
     */
    public function __construct(
        public ?int $promptVersionId,
        public ?string $systemPrompt,
        public string $input,
        public array $targets,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
    ) {}
}
