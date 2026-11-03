<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\DocsIndexRepositoryInterface;
use App\DTOs\ChunkText;
use App\DTOs\IndexedDocument;
use App\Models\DocsChunk;
use App\Models\DocsDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EloquentDocsIndexRepository implements DocsIndexRepositoryInterface
{
    private const STALE = '(embedding IS NULL OR embedding_model IS DISTINCT FROM ?)';

    public function documentHashes(string $source): array
    {
        return DocsDocument::query()->where('source', $source)->pluck('content_hash', 'path')->all();
    }

    public function documentVersionLines(): array
    {
        return DocsDocument::query()
            ->orderBy('source')
            ->orderBy('path')
            ->get(['source', 'path', 'content_hash'])
            ->map(fn (DocsDocument $document): string => $document->source.'|'.$document->path.'|'.$document->content_hash)
            ->all();
    }

    public function replaceDocument(IndexedDocument $document, string $model, CarbonImmutable $indexedAt): int
    {
        return DB::transaction(function () use ($document, $model, $indexedAt): int {
            $row = DocsDocument::query()->updateOrCreate(
                ['source' => $document->source, 'path' => $document->path],
                [
                    'title' => $document->title,
                    'content_hash' => $document->contentHash,
                    'chunk_max_tokens' => $document->chunkMaxTokens,
                    'chunk_merge_below_tokens' => $document->chunkMergeBelowTokens,
                    'indexed_at' => $indexedAt,
                ],
            );

            $kept = [];
            $old = DocsChunk::query()
                ->where('document_id', $row->id)
                ->where('embedding_model', $model)
                ->whereNotNull('embedding')
                ->orderBy('position')
                ->get(['content_hash', 'embedded_at', DB::raw('embedding::text AS embedding_text')]);

            foreach ($old as $chunk) {
                $kept[$chunk->content_hash] ??= [$chunk->getAttribute('embedding_text'), $chunk->getRawOriginal('embedded_at')];
            }

            DocsChunk::query()->where('document_id', $row->id)->delete();

            if ($document->chunks === []) {
                return 0;
            }

            $placeholders = [];
            $bindings = [];

            foreach ($document->chunks as $chunk) {
                [$vector, $embeddedAt] = $kept[$chunk->contentHash] ?? [null, null];
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS vector), ?, ?, now(), now())';
                array_push(
                    $bindings,
                    $row->id,
                    $chunk->position,
                    $chunk->heading,
                    $chunk->body,
                    $chunk->content,
                    $chunk->tokenEstimate,
                    $chunk->oversize,
                    $chunk->contentHash,
                    $vector,
                    $vector === null ? null : $model,
                    $embeddedAt,
                );
            }

            DB::insert(
                'INSERT INTO docs_chunks (document_id, position, heading, body, content, token_estimate, oversize, content_hash, embedding, embedding_model, embedded_at, created_at, updated_at) VALUES '
                .implode(', ', $placeholders),
                $bindings,
            );

            return count($document->chunks);
        });
    }

    public function deleteMissing(string $source, array $paths): int
    {
        return DocsDocument::query()->where('source', $source)->whereNotIn('path', $paths)->delete();
    }

    public function staleChunkIds(string $model): array
    {
        return DocsChunk::query()
            ->whereRaw(self::STALE, [$model])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function pendingEmbeddingCount(string $model): int
    {
        return DocsChunk::query()->whereRaw(self::STALE, [$model])->count();
    }

    public function staleChunks(array $chunkIds, string $model): array
    {
        if ($chunkIds === []) {
            return [];
        }

        return DocsChunk::query()
            ->whereIn('id', $chunkIds)
            ->whereRaw(self::STALE, [$model])
            ->orderBy('id')
            ->get(['id', 'content', 'content_hash'])
            ->map(fn (DocsChunk $chunk): ChunkText => new ChunkText($chunk->id, $chunk->content, $chunk->content_hash))
            ->all();
    }

    public function storeEmbeddings(array $embeddings, string $model, CarbonImmutable $embeddedAt): int
    {
        if ($embeddings === []) {
            return 0;
        }

        $values = implode(', ', array_fill(0, count($embeddings), '(CAST(? AS bigint), CAST(? AS text), CAST(? AS text))'));
        $bindings = [$model, $embeddedAt->format('Y-m-d H:i:s.uP')];

        foreach ($embeddings as $embedding) {
            array_push($bindings, $embedding->chunkId, json_encode($embedding->vector, JSON_THROW_ON_ERROR), $embedding->contentHash);
        }

        return DB::affectingStatement(
            "UPDATE docs_chunks AS c SET
                embedding = CAST(v.vector AS vector),
                embedding_model = CAST(? AS varchar),
                embedded_at = CAST(? AS timestamptz)
            FROM (VALUES {$values}) AS v(id, vector, hash)
            WHERE c.id = v.id AND c.content_hash = v.hash",
            $bindings,
        );
    }
}
