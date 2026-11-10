<?php

declare(strict_types=1);

namespace App\Ai;

use Laravel\Ai\Enums\Lab;

trait UsesActiveProvider
{
    private ?string $providerOverride = null;

    /**
     * Runs this agent on another enabled text provider, at that provider's default text model. The caller has already
     * validated the name.
     */
    public function usingProvider(?string $provider): static
    {
        $this->providerOverride = filled($provider) ? $provider : null;

        return $this;
    }

    /**
     * True when the override names a different provider than the feature is configured with, so a model chosen for the
     * configured provider no longer applies.
     */
    public function hasProviderOverride(): bool
    {
        return $this->providerOverride !== null
            && $this->providerOverride !== app(AiProviders::class)->configured($this->feature())->provider;
    }

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
     * The feature key in config("ai.features"): which provider and model this agent runs on.
     */
    abstract protected function feature(): string;

    /**
     * The SDK reads this to pick the provider. A cloud provider without a key throws before any call.
     */
    public function provider(): string
    {
        return app(AiProviders::class)->forFeature($this->feature(), $this->providerOverride)->provider;
    }

    public function model(): string
    {
        return app(AiProviders::class)->forFeature($this->feature(), $this->providerOverride)->model;
    }

    /**
     * The provider name recorded with a run; it never throws, so a refused or failed run can still be logged.
     */
    public function providerName(): string
    {
        return app(AiProviders::class)->configured($this->feature(), $this->providerOverride)->provider;
    }

    public function modelName(): string
    {
        return app(AiProviders::class)->configured($this->feature(), $this->providerOverride)->model;
    }

    /**
     * @return array<string, mixed>
     */
    protected function activeProviderEntry(): array
    {
        return (array) config('ai.providers.'.$this->providerName(), []);
    }
}
