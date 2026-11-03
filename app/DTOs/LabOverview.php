<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LabOverview
{
    /**
     * @param  list<LabCapability>  $capabilities
     * @param  list<AiRunSummary>  $recentRuns
     */
    public function __construct(
        public string $textProvider,
        public string $textModel,
        public string $embeddingProvider,
        public string $embeddingModel,
        public bool|string $ollamaReachable,
        public array $capabilities,
        public array $recentRuns,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toProps(): array
    {
        return [
            'textProvider' => $this->textProvider,
            'textModel' => $this->textModel,
            'embeddingProvider' => $this->embeddingProvider,
            'embeddingModel' => $this->embeddingModel,
            'ollama' => ['reachable' => $this->ollamaReachable],
            'capabilities' => array_map(fn (LabCapability $capability): array => $capability->toArray(), $this->capabilities),
            'recentRuns' => array_map(fn (AiRunSummary $run): array => $run->toArray(), $this->recentRuns),
        ];
    }
}
