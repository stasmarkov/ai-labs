<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ExportNotFoundException extends RuntimeException
{
    public static function unknown(string $id): self
    {
        return new self("Export \"{$id}\" not found.");
    }

    public static function notGenerated(): self
    {
        return new self('Export not generated.');
    }
}
