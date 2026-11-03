<?php

declare(strict_types=1);

namespace App\Ai;

use Laravel\Ai\Enums\Lab;

trait UsesActiveProvider
{
    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        // The SDK passes the driver ("ollama"), not the config entry name, so match it against the active entry.
        $driver = $provider instanceof Lab ? $provider->value : $provider;
        $entry = $this->activeProviderEntry();

        return ($entry['driver'] ?? null) === $driver ? (array) ($entry['request_options'] ?? []) : [];
    }

    public function maxTokens(): ?int
    {
        $maxTokens = $this->activeProviderEntry()['max_tokens'] ?? null;

        return $maxTokens === null ? null : (int) $maxTokens;
    }

    /**
     * The SDK calls this instead of its own fallback when it exists, so an entry without "timeout" keeps the SDK's 60 s
     * (Promptable::getTimeout).
     */
    public function timeout(): int
    {
        return (int) ($this->activeProviderEntry()['timeout'] ?? 60);
    }

    /**
     * @return array<string, mixed>
     */
    protected function activeProviderEntry(): array
    {
        return (array) config('ai.providers.'.config('ai.default'), []);
    }
}
