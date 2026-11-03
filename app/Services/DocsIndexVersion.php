<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DocsIndexRepositoryInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Fingerprint of the indexed docs, the embedding model and, while any chunk still lacks a vector for that model, the
 * number of such chunks; a cached answer is valid only while it is unchanged. A fully embedded index hashes over the
 * documents and the model alone. The memo is forgotten when docs:index changes a document and when an embed batch ends.
 */
final readonly class DocsIndexVersion
{
    private const KEY = 'docs-answer:index-version';

    private const MEMO_SECONDS = 60;

    public function __construct(
        private DocsIndexRepositoryInterface $index,
        private DocsEmbeddingService $embedding,
    ) {}

    public function current(): string
    {
        return (string) $this->store()->remember(self::KEY, self::MEMO_SECONDS, fn (): string => $this->compute());
    }

    /**
     * Drops the memo. A broken cache store is reported, never thrown: the memo then expires on its own within a minute.
     */
    public function forget(): void
    {
        try {
            $this->store()->forget(self::KEY);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Whether some chunk has no vector for the current embedding model, so the index is only partly searchable.
     */
    public function hasPendingEmbeddings(): bool
    {
        return $this->index->pendingEmbeddingCount($this->embedding->modelLabel()) > 0;
    }

    private function compute(): string
    {
        $lines = array_map(fn (string $line): string => $line."\n", $this->index->documentVersionLines());
        $model = $this->embedding->modelLabel();
        $pending = $this->index->pendingEmbeddingCount($model);

        return hash('sha256', implode('', $lines).$model.($pending > 0 ? '|pending:'.$pending : ''));
    }

    private function store(): Repository
    {
        return Cache::store((string) config('ai.docs.cache.store'));
    }
}
