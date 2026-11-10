<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A feature is configured with a cloud provider that has no key: a server misconfiguration, HTTP 503.
 */
final class ProviderNotConfiguredException extends RuntimeException
{
    public const CODE = 'provider_not_configured';

    public static function forFeature(string $feature, string $provider): self
    {
        return new self("The feature \"{$feature}\" is configured with the provider \"{$provider}\", which has no API key.");
    }
}
