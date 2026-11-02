<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\EmbeddingRunSummary;
use Illuminate\Support\Facades\File;

final readonly class EmbeddingSummaryWriter
{
    /**
     * Write the summary as JSON and return the file path used.
     */
    public function write(EmbeddingRunSummary $summary, ?string $path): string
    {
        $path = $this->resolve($summary, $path);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($summary->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return $path;
    }

    private function resolve(EmbeddingRunSummary $summary, ?string $path): string
    {
        if ($path === null || $path === '') {
            return base_path(sprintf(
                'bench/results/%s-embeddings/embed-%d-%s.json',
                now()->format('Y-m-d'),
                $summary->batch,
                $summary->limit ?? 'all',
            ));
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
