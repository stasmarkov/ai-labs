<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class RagEvalIndexException extends RuntimeException
{
    public static function noChunks(): self
    {
        return new self('No embedded doc chunks; run docs:index first (and a queue worker).');
    }

    public static function notFullyEmbedded(int $embedded, int $total): self
    {
        return new self("Index not fully embedded: {$embedded} of {$total} chunks have a vector; let the queue worker finish.");
    }
}
