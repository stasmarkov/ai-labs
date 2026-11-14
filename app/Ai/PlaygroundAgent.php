<?php

declare(strict_types=1);

namespace App\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * One plain chat call for the prompt playground: the developer's text is the instructions, and the provider, model and
 * settings are those of one target. It has no feature entry, so it never reads the feature configuration.
 */
final class PlaygroundAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function __construct(
        private readonly string $systemPrompt,
        private readonly string $providerName,
        private readonly string $modelName,
        private readonly float $temperatureValue,
        private readonly int $maxTokensValue,
        private readonly int $timeoutSeconds,
    ) {}

    public function instructions(): string
    {
        return $this->systemPrompt;
    }

    public function provider(): string
    {
        return $this->providerName;
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function temperature(): float
    {
        return $this->temperatureValue;
    }

    public function maxTokens(): int
    {
        return $this->maxTokensValue;
    }

    public function timeout(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : $provider;
        $entry = (array) config("ai.providers.{$this->providerName}", []);

        return ($entry['driver'] ?? null) === $driver ? (array) ($entry['request_options'] ?? []) : [];
    }
}
