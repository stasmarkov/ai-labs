<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Ai\AiProviders;
use App\Exceptions\ProviderNotEnabledException;

/**
 * The optional "provider" parameter of the lab's model features: the name of an enabled text provider.
 */
trait NamesProvider
{
    /**
     * @return list<string>
     */
    protected function providerRules(): array
    {
        return ['nullable', 'string', 'max:40'];
    }

    /**
     * @throws ProviderNotEnabledException HTTP 422 `provider_not_enabled`
     */
    protected function passedValidation(): void
    {
        $provider = $this->validated('provider');

        if (filled($provider) && ! app(AiProviders::class)->isEnabledTextProvider((string) $provider)) {
            throw ProviderNotEnabledException::named((string) $provider);
        }
    }

    public function provider(): ?string
    {
        $provider = $this->validated('provider');

        return filled($provider) ? (string) $provider : null;
    }
}
