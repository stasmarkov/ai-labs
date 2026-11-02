<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DocsIndexReport
{
    /**
     * @param  array{commit: string|null, dirty: bool}  $repo
     * @param  array{branch: string|null, commit: string|null}  $laravel
     * @param  list<DocsSourceStats>  $sources
     * @param  list<TokenSample>  $samples
     */
    public function __construct(
        public string $model,
        public string $provider,
        public ?string $ollamaVersion,
        public string $pgvector,
        public array $repo,
        public array $laravel,
        public array $sources,
        public ?float $indexingSeconds,
        public ?DocsEmbeddingWindow $embedding,
        public DocsIndexSizes $sizes,
        public array $samples,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $sources = [];

        foreach ($this->sources as $stats) {
            $sources[$stats->source] = $stats->toArray();
        }

        $ratios = array_map(fn (TokenSample $sample): float => $sample->ratio(), $this->samples);
        $count = max(1, count($this->samples));

        return [
            'model' => $this->model,
            'provider' => $this->provider,
            'ollama_version' => $this->ollamaVersion,
            'pgvector' => $this->pgvector,
            'repo' => $this->repo,
            'laravel' => $this->laravel,
            'sources' => $sources,
            'indexing_seconds' => $this->indexingSeconds,
            'embedding' => $this->embedding?->toArray(),
            'sizes' => $this->sizes->toArray(),
            'token_check' => [
                'chunks' => count($this->samples),
                'mean_ratio' => round(array_sum($ratios) / $count, 3),
                'max_ratio' => round($ratios === [] ? 0.0 : max($ratios), 3),
                'mean_abs_error' => round(array_sum(array_map(fn (TokenSample $sample): int => abs($sample->estimate - $sample->real), $this->samples)) / $count, 2),
                'samples' => array_map(fn (TokenSample $sample): array => $sample->toArray(), $this->samples),
            ],
        ];
    }
}
