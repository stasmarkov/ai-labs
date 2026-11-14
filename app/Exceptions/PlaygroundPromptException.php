<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A playground prompt save that is refused. The reason is a stable code: `invalid_name`, `name_taken`,
 * `unchanged`, `not_found` or `version_conflict`.
 */
final class PlaygroundPromptException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * The HTTP status: 404 for an unknown prompt, 409 for a version number another save took, 422 for the rest.
     */
    public function status(): int
    {
        return match ($this->reason) {
            'not_found' => 404,
            'version_conflict' => 409,
            default => 422,
        };
    }

    public static function invalidName(): self
    {
        return new self('invalid_name', 'A prompt name is 3 to 80 characters of letters, digits, spaces, dots, dashes and underscores, starting and ending with a letter or digit.');
    }

    public static function nameTaken(): self
    {
        return new self('name_taken', 'A prompt with this name already exists.');
    }

    public static function unchanged(): self
    {
        return new self('unchanged', 'The text and settings equal the latest version.');
    }

    public static function notFound(): self
    {
        return new self('not_found', 'The prompt does not exist.');
    }

    public static function versionConflict(): self
    {
        return new self('version_conflict', 'Another save took this version number; try again.');
    }
}
