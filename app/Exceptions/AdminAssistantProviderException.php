<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The admin assistant is configured for a provider other than Ollama: HTTP 422, before any model call.
 */
final class AdminAssistantProviderException extends RuntimeException
{
    public const CODE = 'provider_not_supported';

    public static function named(string $provider): self
    {
        return new self("The admin assistant runs on Ollama only; \"{$provider}\" is not supported.");
    }
}
