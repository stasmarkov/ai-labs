<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class RagEvalResultException extends RuntimeException
{
    public static function labelExists(string $directory): self
    {
        return new self("Label exists: {$directory}. Use another --label or --force to overwrite.");
    }

    public static function cannotWrite(string $directory): self
    {
        return new self("Cannot write results to {$directory}.");
    }

    public static function foreignFiles(string $directory): self
    {
        return new self("Not overwriting {$directory}: it holds files other than recall.json, calibration.json and answers.json.");
    }

    public static function notADirectory(string $directory): self
    {
        return new self("Not overwriting {$directory}: it is not a plain directory.");
    }
}
