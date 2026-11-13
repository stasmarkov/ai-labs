<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The reply to one request of a conversation: the turn plus the id the client sends with the next request.
 */
final readonly class AdminReply
{
    public function __construct(
        public string $conversationId,
        public AdminTurn $turn,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['conversation_id' => $this->conversationId, ...$this->turn->toArray()];
    }
}
