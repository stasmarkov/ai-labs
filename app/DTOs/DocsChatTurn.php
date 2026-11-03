<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One earlier turn of the conversation the page sends along with a follow-up question. It is data for the model to read,
 * never an instruction.
 */
final readonly class DocsChatTurn
{
    public function __construct(
        /** "user" or "assistant" */
        public string $role,
        public string $content,
    ) {}
}
