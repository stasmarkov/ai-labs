<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A finished playground run before it is stored, with one result per target.
 */
final readonly class NewPlaygroundRun
{
    /**
     * @param  list<string>  $inputFlags  the guard patterns the input matched
     * @param  list<PlaygroundResultRecord>  $results
     */
    public function __construct(
        public ?int $promptVersionId,
        public string $systemPrompt,
        public string $input,
        public array $inputFlags,
        public float $temperature,
        public int $maxTokens,
        public string $versionLabel,
        public array $results,
    ) {}
}
