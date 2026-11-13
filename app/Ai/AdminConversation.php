<?php

declare(strict_types=1);

namespace App\Ai;

use App\DTOs\AdminPendingAction;
use Illuminate\Support\Str;

/**
 * The state of one admin-assistant conversation: the messages in the OpenAI shape (without the system prompt), the
 * counters of the question being answered and the pending write, if any. The loop changes it in place; whoever stores
 * it between requests keeps it on the server, so the client can never forge a message.
 */
final class AdminConversation
{
    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function __construct(
        public readonly string $id,
        public array $messages = [],
        public int $steps = 0,
        public int $calls = 0,
        public int $position = 0,
        public ?AdminPendingAction $pending = null,
    ) {}

    public static function start(): self
    {
        return new self((string) Str::uuid());
    }

    /**
     * The last $max messages, cut so that the window starts at a user message and never inside an assistant message
     * and the tool messages that answer it. The last user message always stays in, so a long run of tool messages after
     * it makes the window longer than $max instead of dropping the question.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public static function window(array $messages, int $max): array
    {
        $start = max(0, count($messages) - $max);
        $lastUser = null;

        foreach ($messages as $index => $message) {
            if (($message['role'] ?? null) === 'user') {
                $lastUser = $index;
            }
        }

        if ($lastUser !== null && $lastUser < $start) {
            $start = $lastUser;
        }

        while (isset($messages[$start]) && ($messages[$start]['role'] ?? null) !== 'user') {
            $start++;
        }

        return array_slice($messages, $start);
    }
}
