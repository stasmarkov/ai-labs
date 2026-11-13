<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The request does not fit the state of the conversation. A missing conversation is HTTP 404, every other rule is HTTP 409. The code says which rule it broke.
 */
final class AdminAssistantConflictException extends RuntimeException
{
    public const CONVERSATION_NOT_FOUND = 'conversation_not_found';

    public const ACTION_NOT_PENDING = 'action_not_pending';

    public const ACTION_PENDING = 'action_pending';

    public const BUSY = 'conversation_busy';

    /**
     * @param  string  $reason  one of the constants above
     */
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function conversationNotFound(): self
    {
        return new self('This conversation does not exist or has expired. Start a new one.', self::CONVERSATION_NOT_FOUND);
    }

    public static function actionNotPending(): self
    {
        return new self('There is no pending change with that id in this conversation.', self::ACTION_NOT_PENDING);
    }

    public function status(): int
    {
        return $this->reason === self::CONVERSATION_NOT_FOUND ? 404 : 409;
    }

    public static function actionPending(): self
    {
        return new self('A change is waiting for your decision. Confirm or reject it first.', self::ACTION_PENDING);
    }

    public static function busy(): self
    {
        return new self('Another request of this conversation is still running.', self::BUSY);
    }
}
