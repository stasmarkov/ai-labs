<?php

declare(strict_types=1);

namespace App\Ai;

use App\Exceptions\ProviderNotConfiguredException;
use App\Exceptions\ProviderNotEnabledException;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\OllamaProvider;
use Laravel\Ai\Providers\OpenAiProvider;

/**
 * The one place that reads "ai.default", "ai.default_for_embeddings", "ai.features" and the provider entries' keys.
 * Only names, models and booleans leave this class, never a key value.
 */
final readonly class AiProviders
{
    private const TEXT_FEATURES = ['describe', 'nl-search', 'docs-answer', 'rag-grader', 'photo-vision'];

    /** Text features whose default model is the provider's vision model, when its entry names one. */
    private const VISION_FEATURES = ['photo-vision'];

    /** Features whose model may be chosen per request by the lab selector; the rest always run as configured. */
    public const OVERRIDABLE_FEATURES = ['describe', 'nl-search', 'docs-answer'];

    private const EMBEDDING_FEATURES = ['embeddings-listings', 'embeddings-docs'];

    /**
     * Cloud SDK driver => the key variable of the stock entry. Local or cloud is decided by the entry's driver, never
     * by its name, so a renamed or second cloud entry is still key-checked and rate-limited.
     */
    private const CLOUD_DRIVERS = [
        'anthropic' => 'ANTHROPIC_API_KEY',
        'openai' => 'OPENAI_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
    ];

    /**
     * Every provider that can run here: the fakes (local and testing environments only), Ollama, and each cloud
     * provider with a non-empty key.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            array_keys((array) config('ai.providers', [])),
            fn (string $name): bool => $this->isEnabled($name),
        ));
    }

    /**
     * @return list<string>
     */
    public function enabledTextProviders(): array
    {
        return array_values(array_filter(
            $this->enabled(),
            fn (string $name): bool => config("ai.providers.{$name}.models.text.default") !== null,
        ));
    }

    public function isEnabledTextProvider(string $provider): bool
    {
        return in_array($provider, $this->enabledTextProviders(), true);
    }

    public function isEnabled(string $provider): bool
    {
        $entry = config("ai.providers.{$provider}");

        if (! is_array($entry)) {
            return false;
        }

        if ($this->isCloud($provider)) {
            return $this->hasKey($provider);
        }

        if (($entry['driver'] ?? null) === 'fake-stream') {
            return app()->environment('local', 'testing');
        }

        return true;
    }

    /**
     * Whether a configured provider can serve a request: enabled ones, plus a fake the environment hides from the
     * selector, so a configured fake keeps running outside local and testing.
     */
    private function isRunnable(string $provider): bool
    {
        return $this->isEnabled($provider) || config("ai.providers.{$provider}.driver") === 'fake-stream';
    }

    public function isCloud(string $provider): bool
    {
        return isset(self::CLOUD_DRIVERS[(string) config("ai.providers.{$provider}.driver")]);
    }

    public function hasKey(string $provider): bool
    {
        return filled(config("ai.providers.{$provider}.key"));
    }

    /**
     * The provider and model a feature runs on, with no enabled check: for pages that must render a misconfiguration.
     * A text override names another provider and takes that provider's default text model.
     */
    public function configured(string $feature, ?string $override = null): ProviderChoice
    {
        $embeddings = $this->kindOf($feature) === 'embeddings';
        $provider = (string) (config("ai.features.{$feature}.provider")
            ?: config($embeddings ? 'ai.default_for_embeddings' : 'ai.default'));

        if (filled($override) && ! $embeddings && $override !== $provider) {
            return $this->choice($override, $this->defaultModel($override, false));
        }

        $model = (string) (config("ai.features.{$feature}.model")
            ?: (in_array($feature, self::VISION_FEATURES, true) ? config("ai.providers.{$provider}.models.vision.default") : null)
            ?: $this->defaultModel($provider, $embeddings));

        return $this->choice($provider, $model);
    }

    /**
     * The provider and model one request runs on. An override (the lab selector's "provider") must be an enabled
     * text provider and uses that provider's default text model. Without one, a configured cloud provider with no key
     * is a server misconfiguration.
     *
     * @throws ProviderNotEnabledException
     * @throws ProviderNotConfiguredException
     */
    public function forFeature(string $feature, ?string $override = null): ProviderChoice
    {
        $configured = $this->configured($feature);

        if (filled($override) && $override !== $configured->provider) {
            if (! $this->isEnabledTextProvider($override) || $this->kindOf($feature) === 'embeddings') {
                throw ProviderNotEnabledException::named($override);
            }

            return $this->configured($feature, $override);
        }

        if (! $this->isRunnable($configured->provider)) {
            throw ProviderNotConfiguredException::forFeature($feature, $configured->provider);
        }

        return $configured;
    }

    /**
     * What each provider can do, for the providers guide: text and embeddings come from the SDK provider's interfaces,
     * local or cloud and the key variable from this class.
     *
     * @return array<string, array{text: bool, streaming: bool, structured: bool, embeddings: ?int, cloud: bool, keyVariable: ?string}>
     */
    public function capabilities(): array
    {
        $rows = [];

        foreach ((array) config('ai.providers', []) as $name => $entry) {
            $class = match ($entry['driver'] ?? null) {
                'anthropic' => AnthropicProvider::class,
                'openai' => OpenAiProvider::class,
                'gemini' => GeminiProvider::class,
                'ollama' => OllamaProvider::class,
                default => null,
            };
            $text = $class === null ? isset($entry['models']['text']) : $this->implements($class, TextProvider::class);
            $embeddings = $class !== null && $this->implements($class, EmbeddingProvider::class) && isset($entry['models']['embeddings']);

            $rows[$name] = [
                'text' => $text,
                'streaming' => $text,
                'structured' => $text,
                'embeddings' => $embeddings ? (int) $entry['models']['embeddings']['dimensions'] : null,
                'cloud' => $this->isCloud($name),
                'keyVariable' => self::CLOUD_DRIVERS[(string) ($entry['driver'] ?? '')] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  class-string  $class
     */
    private function implements(string $class, string $interface): bool
    {
        return in_array($interface, class_implements($class) ?: [], true);
    }

    private function choice(string $provider, string $model): ProviderChoice
    {
        return new ProviderChoice($provider, $model, (string) config("ai.providers.{$provider}.driver", $provider));
    }

    private function defaultModel(string $provider, bool $embeddings): string
    {
        return (string) config('ai.providers.'.$provider.'.models.'.($embeddings ? 'embeddings' : 'text').'.default');
    }

    private function kindOf(string $feature): string
    {
        return match (true) {
            in_array($feature, self::TEXT_FEATURES, true) => 'text',
            in_array($feature, self::EMBEDDING_FEATURES, true) => 'embeddings',
            default => throw new InvalidArgumentException("Unknown AI feature \"{$feature}\"."),
        };
    }
}
