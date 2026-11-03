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
            capabilities: [
                new LabCapability('describe', 'Describe', '/ai/describe', 'ready', 'SL-22'),
                new LabCapability('semantic', 'Semantic search', '/ai/semantic', 'ready', 'SL-24'),
                new LabCapability('docs', 'Docs search', '/ai/docs', 'ready', 'SL-32'),
                new LabCapability('chat', 'Chat', '/ai/chat', 'coming', 'SL-45'),
                new LabCapability('photos', 'Photos', '/ai/photos', 'coming', 'SL-36'),
            ],
            recentRuns: $this->runs->recent(self::RECENT_RUNS),
        );
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
