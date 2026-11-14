<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\Ai\OllamaServerInfo;
use App\DTOs\ModelOption;
use App\DTOs\PlaygroundTarget;

/**
 * The providers and models the playground's target pickers offer, and the targets picked first.
 */
final readonly class PlaygroundTargetCatalog
{
    public function __construct(private AiProviders $providers, private OllamaServerInfo $ollama) {}

    /**
     * Every enabled text provider with the models it offers: the installed text models for Ollama, the configured ones
     * for the rest.
     *
     * @return list<array{name: string, cloud: bool, models: list<string>}>
     */
    public function providers(): array
    {
        $installed = null;
        $catalog = [];

        foreach ($this->providers->enabledTextProviders() as $name) {
            if (config("ai.providers.{$name}.driver") === 'ollama') {
                $installed ??= array_values(array_map(
                    fn (ModelOption $option): string => $option->name,
                    array_filter($this->ollama->models(), fn (ModelOption $option): bool => $option->kind === ModelOption::TEXT),
                ));
                $models = $installed;
            } else {
                $models = array_values(array_unique(array_filter(
                    [config("ai.providers.{$name}.models.text.default"), ...$this->ollama->available($name)],
                    is_string(...),
                )));
            }

            $catalog[] = ['name' => $name, 'cloud' => $this->providers->isCloud($name), 'models' => $models];
        }

        return $catalog;
    }

    /**
     * The configured default targets that are offered (at most the run's maximum), topped up with the first offered models when fewer than 2 remain.
     *
     * @return list<array{provider: string, model: string}>
     */
    public function defaultTargets(): array
    {
        $catalog = $this->providers();
        $offered = [];

        foreach ($catalog as $provider) {
            foreach ($provider['models'] as $model) {
                $offered[(new PlaygroundTarget($provider['name'], $model))->key()] = ['provider' => $provider['name'], 'model' => $model];
            }
        }

        $targets = [];

        foreach ((array) config('ai.playground.default_targets', []) as $entry) {
            [$provider, $model] = array_pad(explode(':', (string) $entry, 2), 2, '');
            $key = "{$provider}:".OllamaServerInfo::canonical($model);

            if (isset($offered[$key]) && count($targets) < PlaygroundRunner::MAX_TARGETS) {
                $targets[$key] = $offered[$key];
            }
        }

        foreach ($offered as $key => $target) {
            if (count($targets) >= PlaygroundRunner::MIN_TARGETS) {
                break;
            }

            $targets[$key] ??= $target;
        }

        return array_values($targets);
    }
}
