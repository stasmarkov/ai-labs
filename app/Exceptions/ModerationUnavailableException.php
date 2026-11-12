<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The model could not be reached while moderating a listing: HTTP 503. The failed attempts are still logged runs.
 */
final class ModerationUnavailableException extends RuntimeException
{
    public const CODE = 'moderation_unavailable';

    /**
     * @param  list<string>  $invocationIds
     */
    public function __construct(public readonly array $invocationIds, public readonly int $attempts)
    {
        parent::__construct('The moderation model could not be reached.');
    }
}
