<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A caller named a provider that cannot run here (unknown, or a cloud provider without a key): a client error, HTTP 422.
 */
final class ProviderNotEnabledException extends RuntimeException
{
    public const CODE = 'provider_not_enabled';

    public static function named(string $provider): self
    {
        return new self("The provider \"{$provider}\" is not enabled.");
    }
}
