<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The first choice of one /v1/chat/completions answer, with the token usage it reported.
 */
final readonly class AdminChatCompletion
{
    /**
     * @param  list<AdminToolCall>  $toolCalls
     */
    public function __construct(
        public string $content,
        public array $toolCalls,
        public ?int $tokensIn,
        public ?int $tokensOut,
    ) {}
}
