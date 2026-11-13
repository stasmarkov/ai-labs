<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\Ai\OllamaServerInfo;
use App\Contracts\AiSettingsRepositoryInterface;
use App\Contracts\DocsIndexRepositoryInterface;
use App\Contracts\ListingDocumentRepositoryInterface;
use App\Contracts\ListingPhotoRepositoryInterface;
use App\DTOs\AiSettingChoice;
use App\DTOs\ModelOption;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The rules of the settings page: which providers and models a feature may be saved with, and what a save does.
 */
final readonly class AiSettingsService
{
    public const OTHER_MODEL_PATTERN = AiSettingChoice::MODEL_PATTERN;

    public function __construct(
        private AiProviders $providers,
        private AiSettingsRepositoryInterface $settings,
        private OllamaServerInfo $ollama,
        private ListingDocumentRepositoryInterface $listingDocuments,
        private DocsIndexRepositoryInterface $docsIndex,
        private ListingPhotoRepositoryInterface $photos,
    ) {}

    /**
     * One row per feature: the effective choice and its source, the offered providers with their models, and a
     * warning when the saved provider can no longer run. Holds names and booleans only.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $saved = $this->settings->all();
        $rows = [];

        foreach ($this->providers->offeredFeatures() as $feature => $kind) {
            $effective = $this->providers->effective($feature);
            $savedChoice = $saved[$feature] ?? null;

            $rows[] = [
                'feature' => $feature,
                'kind' => $kind,
                'effective' => [
                    'provider' => $effective->choice->provider,
                    'model' => $effective->choice->model,
                    'source' => $effective->source,
                    'modelSource' => $effective->modelSource,
                    ...$this->installation($effective->choice->provider, $effective->choice->model),
                ],
                'saved' => $savedChoice?->toArray(),
                'savedProviderEnabled' => $savedChoice === null ? null : $this->providers->isEnabled($savedChoice->provider),
                'providers' => $this->withCurrentModel($this->options($feature), $effective->choice->provider, $effective->choice->model),
                'index' => $kind !== 'text' ? $this->indexStatus($feature, $effective->choice->provider.'/'.$effective->choice->model) : null,
            ];
        }

        return $rows;
    }

    /**
     * Whether an Ollama model is pulled, and the command that pulls it. Both are null for other providers and when
     * Ollama cannot be reached, so an unreachable server never reads as "not installed".
     *
     * @return array{installed: ?bool, pullCommand: ?string}
     */
    private function installation(string $provider, string $model): array
    {
        if (config("ai.providers.{$provider}.driver") !== 'ollama') {
            return ['installed' => null, 'pullCommand' => null];
        }

        $installed = array_map(fn (ModelOption $option): string => $option->name, $this->ollama->models());

        if ($installed === []) {
            return ['installed' => null, 'pullCommand' => null];
        }

        $present = in_array(OllamaServerInfo::canonical($model), $installed, true);

        // The command is shown for copying, so it is offered only for an id made of safe characters.
        $copyable = ! $present && preg_match(AiSettingChoice::MODEL_PATTERN, $model) === 1;

        return ['installed' => $present, 'pullCommand' => $copyable ? "ollama pull {$model}" : null];
    }

    /**
     * Keeps the effective model in its provider's select even when the list lacks it (an Ollama model that is not
     * pulled), so the select never shows a blank value. Saving still validates against the real list.
     *
     * @param  list<array{name: string, models: list<string>, free: bool, note: ?string}>  $options
     * @return list<array{name: string, models: list<string>, free: bool, note: ?string}>
     */
    private function withCurrentModel(array $options, string $provider, string $model): array
    {
        foreach ($options as $index => $option) {
            if ($option['name'] === $provider && config("ai.providers.{$provider}.driver") === 'ollama' && ! in_array($model, $option['models'], true)) {
                $options[$index]['models'][] = $model;
            }
        }

        return $options;
    }

    /**
     * The vector table behind an embeddings feature: the rows, the rows with a vector, the rows whose vector was made
     * with another model than the effective one (the warning), and the command that re-indexes.
     *
     * @return array{table: string, command: string, total: int, indexed: int, current: int, foreign: int, warning: bool}
     */
    public function indexStatus(string $feature, string $label): array
    {
        [$table, $command, $count] = match ($feature) {
            'embeddings-listings' => ['listing_documents', 'ddev artisan listings:embed --fresh --wait', $this->listingDocuments->embeddingCount($label)],
            'embeddings-photos' => ['listing_photos', 'ddev artisan photos:embed --fresh --wait', $this->photos->embeddingCount($label)],
            default => ['docs_chunks', 'ddev artisan docs:index', $this->docsIndex->embeddingCount($label)],
        };

        return [
            'table' => $table,
            'command' => $command,
            'total' => $count->total,
            'indexed' => $count->embedded,
            'current' => $count->current,
            'foreign' => $count->foreign(),
            'warning' => $count->foreign() > 0,
        ];
    }

    /**
     * The providers a feature may be saved with, each with its model ids, whether any other id is accepted, and a note.
     *
     * @return list<array{name: string, models: list<string>, free: bool, note: ?string}>
     */
    public function options(string $feature): array
    {
        $kind = $this->kind($feature);
        $options = [];

        foreach ($this->enabledFor($kind, $feature) as $name) {
            $options[] = ['name' => $name, ...$this->modelsFor($feature, $name, $kind)];
        }

        return $options;
    }

    /**
     * The reason a save is refused, or null when the provider and model are allowed for the feature.
     */
    public function modelError(string $feature, string $provider, string $model): ?string
    {
        $kind = $this->kind($feature);

        if (! in_array($provider, $this->enabledFor($kind, $feature), true)) {
            return "The provider {$provider} is not enabled for this feature.";
        }

        $allowed = $this->modelsFor($feature, $provider, $kind);

        $compared = config("ai.providers.{$provider}.driver") === 'ollama' ? OllamaServerInfo::canonical($model) : $model;

        if (in_array($compared, $allowed['models'], true)) {
            return null;
        }

        if ($allowed['free'] && preg_match(self::OTHER_MODEL_PATTERN, $model) === 1) {
            return null;
        }

        return $allowed['note'] ?? "The model {$model} is not offered for {$provider}.";
    }

    /**
     * Whether the save changes an embeddings model, which leaves the stored vectors stale until a re-index.
     */
    public function changesEmbeddingModel(string $feature, string $provider, string $model): bool
    {
        if ($this->kind($feature) === 'text') {
            return false;
        }

        $effective = $this->providers->effective($feature)->choice;

        return $effective->provider !== $provider || $this->canonicalFor($provider, $effective->model) !== $this->canonicalFor($provider, $model);
    }

    /**
     * Saves the choice, or deletes the row when it equals configuration. Returns the flash message.
     */
    public function save(AiSettingChoice $choice): string
    {
        $this->kind($choice->feature);

        $choice = new AiSettingChoice($choice->feature, $choice->provider, $this->canonicalFor($choice->provider, $choice->model));
        $configuration = $this->providers->withoutSettings($choice->feature)->choice;

        if ($configuration->provider === $choice->provider && $this->canonicalFor($choice->provider, $configuration->model) === $choice->model) {
            $this->settings->reset($choice->feature);

            return "{$choice->feature} matches configuration; nothing saved.";
        }

        $this->settings->save($choice);

        return "{$choice->feature} now uses {$choice->provider} / {$choice->model}.";
    }

    public function reset(string $feature): string
    {
        $this->kind($feature);

        $this->settings->reset($feature);

        return "{$feature} is back to configuration.";
    }

    /**
     * The kind of a feature the settings page offers. A feature that is not offered, such as the admin assistant while
     * it is switched off, is not found.
     *
     * @throws NotFoundHttpException
     */
    private function kind(string $feature): string
    {
        return $this->providers->offeredFeatures()[$feature] ?? throw new NotFoundHttpException;
    }

    /**
     * @return array{models: list<string>, free: bool, note: ?string}
     */
    private function modelsFor(string $feature, string $provider, string $kind): array
    {
        $key = AiProviders::modelKey($kind);
        $driver = (string) config("ai.providers.{$provider}.driver");
        $default = config("ai.providers.{$provider}.models.{$key}.default");

        if ($driver === 'ollama') {
            return $this->ollamaModels($feature, $kind === 'embeddings');
        }

        if ($this->providers->isCloud($provider)) {
            $models = array_merge(is_string($default) ? [$default] : [], $this->ollama->available($provider, $key));

            return ['models' => array_values(array_unique($models)), 'free' => true, 'note' => null];
        }

        $available = config("ai.providers.{$provider}.models.{$key}.available");
        $models = is_array($available) ? array_merge(is_string($default) ? [$default] : [], $available) : (is_string($default) ? [$default] : []);

        return ['models' => array_values(array_unique(array_filter($models, 'is_string'))), 'free' => false, 'note' => null];
    }

    /**
     * @return list<string>
     */
    private function enabledFor(string $kind, string $feature): array
    {
        $enabled = match ($kind) {
            'embeddings' => $this->providers->enabledEmbeddingProviders(),
            'image-embeddings' => $this->providers->enabledImageEmbeddingProviders(),
            default => $this->providers->enabledTextProviders(),
        };

        if ($feature !== AiProviders::ADMIN_ASSISTANT) {
            return $enabled;
        }

        return array_values(array_filter($enabled, fn (string $name): bool => config("ai.providers.{$name}.driver") === 'ollama'));
    }

    /**
     * @return array{models: list<string>, free: bool, note: ?string}
     */
    private function ollamaModels(string $feature, bool $embeddings): array
    {
        $kind = $embeddings ? ModelOption::EMBEDDING : ModelOption::TEXT;
        $installed = array_values(array_map(
            fn (ModelOption $option): string => $option->name,
            array_filter($this->ollama->models(), fn (ModelOption $option): bool => $option->kind === $kind && ($feature !== 'photo-vision' || $option->vision)),
        ));

        if ($installed !== []) {
            return ['models' => $installed, 'free' => false, 'note' => null];
        }

        // Unreachable, or reachable with no model of this kind: offer only Ollama ids from configuration and settings.
        $isOllama = fn (?string $provider): bool => $provider !== null && config("ai.providers.{$provider}.driver") === 'ollama';
        $saved = $this->settings->all()[$feature] ?? null;
        $configured = [
            $isOllama($this->providers->withoutSettings($feature)->choice->provider) ? config("ai.features.{$feature}.model") : null,
            $feature === 'photo-vision'
                ? config('ai.providers.ollama.models.vision.default')
                : config('ai.providers.ollama.models.'.($embeddings ? 'embeddings' : 'text').'.default'),
            $isOllama($saved?->provider) ? $saved?->model : null,
        ];
        $note = $this->ollama->models() === []
            ? 'Ollama is not reachable; pick a configured model or try again.'
            : 'No installed Ollama model of this kind was found; pick a configured model or pull one.';

        return [
            'models' => array_values(array_unique(array_filter($configured, fn (mixed $id): bool => is_string($id) && $id !== ''))),
            'free' => false,
            'note' => $note,
        ];
    }

    /**
     * Ollama ids without their default ":latest" tag, so "bge-m3" and "bge-m3:latest" are one model; other ids as given.
     */
    private function canonicalFor(string $provider, string $model): string
    {
        return config("ai.providers.{$provider}.driver") === 'ollama' ? OllamaServerInfo::canonical($model) : $model;
    }
}
