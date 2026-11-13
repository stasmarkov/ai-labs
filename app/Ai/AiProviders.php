<?php

declare(strict_types=1);

namespace App\Ai;

use App\Contracts\AiSettingsRepositoryInterface;
use App\DTOs\AiSettingChoice;
use App\DTOs\ResolvedChoice;
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
    public const SOURCE_REQUEST = 'request';

    public const SOURCE_SETTINGS = 'settings';

    public const SOURCE_CONFIGURATION = 'configuration';

    public const SOURCE_DEFAULT = 'default';

    private const TEXT_FEATURES = ['describe', 'nl-search', 'docs-answer', 'rag-grader', 'photo-vision', 'assistant', 'moderation'];

    /** A text feature that resolves like the others but is on the settings page only while offeredFeatures() adds it. */
    public const ADMIN_ASSISTANT = 'admin-assistant';

    /** Text features whose default model is the provider's vision model, when its entry names one. */
    private const VISION_FEATURES = ['photo-vision'];

    /** Features whose model may be chosen per request by the lab selector; the rest always run as configured. */
    public const OVERRIDABLE_FEATURES = ['describe', 'nl-search', 'docs-answer', 'assistant', 'moderation'];

    private const EMBEDDING_FEATURES = ['embeddings-listings', 'embeddings-docs'];

    private const IMAGE_EMBEDDING_FEATURES = ['embeddings-photos'];

    /**
     * Cloud SDK driver => the key variable of the stock entry. Local or cloud is decided by the entry's driver, never
     * by its name, so a renamed or second cloud entry is still key-checked and rate-limited.
     */
    private const CLOUD_DRIVERS = [
        'anthropic' => 'ANTHROPIC_API_KEY',
        'openai' => 'OPENAI_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
    ];

    public function __construct(private ?AiSettingsRepositoryInterface $settings = null) {}

    /**
     * Every provider that can run here: the fakes (local and testing environments only), Ollama, and each cloud
     * provider with a non-empty key. Image encoders are not listed; enabledImageEmbeddingProviders() names them.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            array_keys((array) config('ai.providers', [])),
            fn (string $name): bool => ! $this->isImageEncoder($name) && $this->isEnabled($name),
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
        return $this->resolveConfigured($feature, $override)->choice;
    }

    /**
     * Every feature key with its kind: text, embeddings or image-embeddings.
     *
     * @return array<string, string>
     */
    public static function features(): array
    {
        return [
            ...array_fill_keys(self::TEXT_FEATURES, 'text'),
            ...array_fill_keys(self::EMBEDDING_FEATURES, 'embeddings'),
            ...array_fill_keys(self::IMAGE_EMBEDDING_FEATURES, 'image-embeddings'),
        ];
    }

    /**
     * Whether saved settings apply: the local environment with the settings switch on.
     */
    public function settingsAvailable(): bool
    {
        return app()->environment('local') && (bool) config('ai.settings_ui', true);
    }

    /**
     * Whether the admin assistant is offered: the local environment with the admin assistant switch on.
     */
    public function adminAssistantAvailable(): bool
    {
        return app()->environment('local') && (bool) config('ai.admin_assistant.enabled', true);
    }

    /**
     * The features the settings page offers: every listed feature, plus the admin assistant while it is available.
     *
     * @return array<string, string>
     */
    public function offeredFeatures(): array
    {
        return [
            ...self::features(),
            ...($this->adminAssistantAvailable() ? [self::ADMIN_ASSISTANT => 'text'] : []),
        ];
    }

    /**
     * Enabled providers that name an embeddings model.
     *
     * @return list<string>
     */
    public function enabledEmbeddingProviders(): array
    {
        return array_values(array_filter(
            $this->enabled(),
            fn (string $name): bool => config("ai.providers.{$name}.models.embeddings.default") !== null,
        ));
    }

    /**
     * Enabled providers that name an image-embeddings model.
     *
     * @return list<string>
     */
    public function enabledImageEmbeddingProviders(): array
    {
        return array_values(array_filter(
            array_keys((array) config('ai.providers', [])),
            fn (string $name): bool => $this->isImageEncoder($name)
                && $this->isEnabled($name)
                && config("ai.providers.{$name}.models.image_embeddings.default") !== null,
        ));
    }

    private function isImageEncoder(string $provider): bool
    {
        return config("ai.providers.{$provider}.driver") === 'image-encoder';
    }

    /**
     * What the feature resolves to with no request override: a saved setting that counts, else configuration or default.
     * No enabled check, so a page can show a misconfiguration.
     */
    public function effective(string $feature): ResolvedChoice
    {
        return $this->resolveConfigured($feature);
    }

    /**
     * What the feature resolves to when no setting is saved: configuration, then the default.
     */
    public function withoutSettings(string $feature): ResolvedChoice
    {
        return $this->configurationChoice($feature, $this->kindOf($feature));
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
        return $this->resolve($feature, $override)->choice;
    }

    /**
     * Like forFeature(), with the level that decided: request, settings, configuration or default.
     *
     * @throws ProviderNotEnabledException
     * @throws ProviderNotConfiguredException
     */
    public function resolve(string $feature, ?string $override = null): ResolvedChoice
    {
        $baseline = $this->resolveConfigured($feature);

        if (filled($override) && $override !== $baseline->choice->provider) {
            if (! $this->isEnabledTextProvider($override) || $this->kindOf($feature) !== 'text') {
                throw ProviderNotEnabledException::named($override);
            }

            return $this->resolveConfigured($feature, $override, $baseline);
        }

        if (! $this->isRunnable($baseline->choice->provider)) {
            throw ProviderNotConfiguredException::forFeature($feature, $baseline->choice->provider);
        }

        return $baseline;
    }

    private function resolveConfigured(string $feature, ?string $override = null, ?ResolvedChoice $baseline = null): ResolvedChoice
    {
        $kind = $this->kindOf($feature);
        $baseline ??= $this->baseline($feature, $kind);

        if (filled($override) && $kind === 'text' && $override !== $baseline->choice->provider) {
            $configuration = $this->configurationChoice($feature, $kind)->choice;
            $fromConfiguration = $configuration->provider === $override;
            $model = $fromConfiguration ? $configuration->model : $this->defaultModel($override, $kind);
            $modelSource = $fromConfiguration && filled(config("ai.features.{$feature}.model")) ? self::SOURCE_CONFIGURATION : self::SOURCE_DEFAULT;

            return new ResolvedChoice($this->choice($override, $model), self::SOURCE_REQUEST, $modelSource);
        }

        return $baseline;
    }

    /**
     * The level below the request: a saved setting, then configuration, then the provider's default model.
     */
    private function baseline(string $feature, string $kind): ResolvedChoice
    {
        $saved = $this->savedChoice($feature);

        if ($saved !== null) {
            return new ResolvedChoice($this->choice($saved->provider, $saved->model), self::SOURCE_SETTINGS);
        }

        return $this->configurationChoice($feature, $kind);
    }

    private function configurationChoice(string $feature, string $kind): ResolvedChoice
    {
        $configuredProvider = config("ai.features.{$feature}.provider");
        $configuredModel = config("ai.features.{$feature}.model");
        $provider = (string) ($configuredProvider ?: config($kind === 'embeddings' ? 'ai.default_for_embeddings' : 'ai.default'));

        $model = (string) ($configuredModel
            ?: (in_array($feature, self::VISION_FEATURES, true) ? config("ai.providers.{$provider}.models.vision.default") : null)
            ?: $this->defaultModel($provider, $kind));

        // The row's source is the level that supplied the provider; the model carries its own source label.
        $source = filled($configuredProvider) ? self::SOURCE_CONFIGURATION : self::SOURCE_DEFAULT;

        return new ResolvedChoice($this->choice($provider, $model), $source, filled($configuredModel) ? self::SOURCE_CONFIGURATION : self::SOURCE_DEFAULT);
    }

    /**
     * A saved setting counts only in the local environment, with the settings switch on, and for an enabled provider,
     * so a copied database cannot change a production model.
     */
    private function savedChoice(string $feature): ?AiSettingChoice
    {
        if (! $this->settingsAvailable()) {
            return null;
        }

        $saved = ($this->settings ?? app(AiSettingsRepositoryInterface::class))->all()[$feature] ?? null;

        return $saved !== null && $this->isEnabled($saved->provider) ? $saved : null;
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

    private function defaultModel(string $provider, string $kind): string
    {
        return (string) config('ai.providers.'.$provider.'.models.'.self::modelKey($kind).'.default');
    }

    /**
     * The key under a provider's "models" that holds the entries of a feature kind.
     */
    public static function modelKey(string $kind): string
    {
        return $kind === 'image-embeddings' ? 'image_embeddings' : $kind;
    }

    private function kindOf(string $feature): string
    {
        return match (true) {
            in_array($feature, self::TEXT_FEATURES, true), $feature === self::ADMIN_ASSISTANT => 'text',
            in_array($feature, self::EMBEDDING_FEATURES, true) => 'embeddings',
            in_array($feature, self::IMAGE_EMBEDDING_FEATURES, true) => 'image-embeddings',
            default => throw new InvalidArgumentException("Unknown AI feature \"{$feature}\"."),
        };
    }
}
