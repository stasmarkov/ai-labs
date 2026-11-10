<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\DTOs\LabProviderOption;

final readonly class LabProviderService
{
    public function __construct(private AiProviders $providers) {}

    /**
     * The enabled text providers for a feature's selector, plus the configured one even without a key, as names and
     * flags only.
     *
     * @return list<array{name: string, configured: bool, hasKey: bool}>
     */
    public function textOptions(string $feature): array
    {
        $configured = $this->providers->configured($feature)->provider;

        $names = $this->providers->enabledTextProviders();

        if (! in_array($configured, $names, true) && config("ai.providers.{$configured}.models.text.default") !== null) {
            $names = array_values(array_filter(
                array_keys((array) config('ai.providers', [])),
                fn (string $name): bool => $name === $configured || in_array($name, $names, true),
            ));
        }

        return array_map(
            fn (string $name): array => (new LabProviderOption(
                $name,
                $name === $configured,
                ! $this->providers->isCloud($name) || $this->providers->hasKey($name),
            ))->toArray(),
            $names,
        );
    }

    /**
     * The provider and model that embed listings or docs, shown as text on the search pages.
     *
     * @return array{provider: string, model: string}
     */
    public function embeddings(string $feature): array
    {
        $choice = $this->providers->configured($feature);

        return ['provider' => $choice->provider, 'model' => $choice->model];
    }
}
