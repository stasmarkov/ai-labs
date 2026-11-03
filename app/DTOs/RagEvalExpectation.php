<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One document an on-topic question should be answered from, and the phrases that prove a chunk carries the answer.
 */
final readonly class RagEvalExpectation
{
    /**
     * @param  list<string>  $evidence
     */
    public function __construct(
        public string $source,
        public string $path,
        public array $evidence,
    ) {}

    public function doc(): string
    {
        return "{$this->source}:{$this->path}";
    }
}
