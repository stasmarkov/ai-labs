<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class RagGraderReplyException extends RuntimeException
{
    public function __construct(public readonly string $failure)
    {
        parent::__construct("The grader reply is unusable: {$failure}.");
    }

    public static function because(string $failure): self
    {
        return new self($failure);
    }
}
