<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A decision is refused. Either it does not fit the state of the change (it is unknown, already decided, claimed or
 * expired: HTTP 409, code `change_not_pending`), or it did not carry the confirmation nonce of the page (HTTP 403, code
 * `confirmation_refused`).
 */
final class PendingChangeConflictException extends RuntimeException
{
    public const NOT_PENDING = 'change_not_pending';

    public const REFUSED = 'confirmation_refused';

    private function __construct(string $message, public readonly string $reason, private readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function notPending(): self
    {
        return new self('This change is no longer pending: it was unknown, already decided or it expired.', self::NOT_PENDING);
    }

    public static function refused(): self
    {
        return new self('Open the change page in the browser and confirm or reject it there.', self::REFUSED, 403);
    }

    public function status(): int
    {
        return $this->status;
    }
}
