<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\OllamaServerInfo;
use App\Contracts\AiRunRepositoryInterface;
use App\DTOs\LabCapability;
use App\DTOs\LabOverview;

final readonly class LabOverviewService
{
    private const RECENT_RUNS = 20;

    public function __construct(
        private AiRunRepositoryInterface $runs,
        private OllamaServerInfo $ollama,
    ) {}

    public function overview(): LabOverview
    {
        $textProvider = (string) config('ai.default');
        $embeddingProvider = (string) config('ai.default_for_embeddings');

        return new LabOverview(
            textProvider: $textProvider,
            textModel: (string) config("ai.providers.{$textProvider}.models.text.default"),
            embeddingProvider: $embeddingProvider,
            embeddingModel: (string) config("ai.providers.{$embeddingProvider}.models.embeddings.default"),
            ollamaReachable: $this->usesOllama([$textProvider, $embeddingProvider]) ? $this->ollama->reachable() : 'not-used',
            capabilities: $this->capabilities(),
            recentRuns: $this->runs->recent(self::RECENT_RUNS),
        );
    }

    /**
     * @return array{textProvider: string, textModel: string, embeddingProvider: string, embeddingModel: string, ollama: array{reachable: bool|string}, navigation: list<array{href: string, label: string}>}
     */
    public function topBar(): array
    {
        $textProvider = (string) config('ai.default');
        $embeddingProvider = (string) config('ai.default_for_embeddings');

        return [
            'textProvider' => $textProvider,
            'textModel' => (string) config("ai.providers.{$textProvider}.models.text.default"),
            'embeddingProvider' => $embeddingProvider,
            'embeddingModel' => (string) config("ai.providers.{$embeddingProvider}.models.embeddings.default"),
            'ollama' => [
                'reachable' => $this->usesOllama([$textProvider, $embeddingProvider]) ? $this->ollama->reachable() : 'not-used',
            ],
            'navigation' => $this->navigation(),
        ];
    }

    /**
     * The lab's pages, the one list behind the overview cards, the navigation and the placeholders.
     *
     * @return list<LabCapability>
     */
    public function capabilities(): array
    {
        return [
            new LabCapability('describe', 'Describe', route('ai.describe', absolute: false), 'ready', 'SL-22'),
            new LabCapability('semantic', 'Semantic search', route('ai.semantic', absolute: false), 'ready', 'SL-24'),
            new LabCapability('docs', 'Docs search', route('ai.docs', absolute: false), 'ready', 'SL-32'),
            new LabCapability('chat', 'Chat', route('ai.chat', absolute: false), 'coming', 'SL-45'),
            new LabCapability('photos', 'Photos', route('ai.photos', absolute: false), 'coming', 'SL-36'),
        ];
    }

    public function capability(string $key): LabCapability
    {
        foreach ($this->capabilities() as $capability) {
            if ($capability->key === $key) {
                return $capability;
            }
        }

        throw new \InvalidArgumentException("Unknown lab capability {$key}.");
    }

    /**
     * @return list<array{href: string, label: string}>
     */
    private function navigation(): array
    {
        return [
            ['href' => route('ai.overview', absolute: false), 'label' => 'Overview'],
            ...array_map(fn (LabCapability $capability): array => ['href' => $capability->route, 'label' => $capability->title], $this->capabilities()),
        ];
    }

    /**
     * @param  list<string>  $providers
     */
    private function usesOllama(array $providers): bool
    {
        foreach ($providers as $provider) {
            if (config("ai.providers.{$provider}.driver") === 'ollama') {
                return true;
            }
        }

        return false;
    }
}
