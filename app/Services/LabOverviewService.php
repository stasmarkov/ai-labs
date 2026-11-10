<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\OllamaServerInfo;
use App\Contracts\AiRunRepositoryInterface;
use App\DTOs\LabCapability;
use App\DTOs\LabOverview;
use App\Guides\GuideRepository;

final readonly class LabOverviewService
{
    private const RECENT_RUNS = 20;

    /** The lab pages in order: key (also the guide key), title, route name and status. */
    private const PAGES = [
        ['describe', 'Describe', 'ai.describe', 'ready'],
        ['semantic', 'Semantic search', 'ai.semantic', 'ready'],
        ['nl-search', 'NL search', 'ai.nl-search', 'ready'],
        ['docs', 'Docs search', 'ai.docs', 'ready'],
        ['chat', 'Chat', 'ai.chat', 'ready'],
        ['photos', 'Photos', 'ai.photos', 'coming'],
    ];

    public function __construct(
        private AiRunRepositoryInterface $runs,
        private OllamaServerInfo $ollama,
        private GuideRepository $guides,
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
     * The lab's pages, the one list behind the overview cards, the home page and the placeholders. Each summary is
     * read from the page's guide file, so the cards and the guide index say the same thing.
     *
     * @return list<LabCapability>
     */
    public function capabilities(): array
    {
        return array_map(fn (array $page): LabCapability => new LabCapability(
            $page[0],
            $page[1],
            route($page[2], absolute: false),
            $page[3],
            $this->guides->find($page[0])->summary,
            $page[0],
        ), self::PAGES);
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
            ['href' => route('ai.guides.index', absolute: false), 'label' => 'Guides'],
            ...array_map(fn (array $page): array => ['href' => route($page[2], absolute: false), 'label' => $page[1]], self::PAGES),
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
