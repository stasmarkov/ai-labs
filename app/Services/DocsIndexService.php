<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DocsIndexRepositoryInterface;
use App\DTOs\CorpusFile;
use App\DTOs\DocsIndexRun;
use App\DTOs\IndexedDocument;
use App\Jobs\EmbedDocsBatchJob;
use Carbon\CarbonImmutable;

final readonly class DocsIndexService
{
    public function __construct(
        private DocsCorpus $corpus,
        private MarkdownChunker $chunker,
        private DocsIndexRepositoryInterface $index,
        private DocsEmbeddingService $embedding,
    ) {}

    /**
     * @return list<string>
     */
    public function sources(): array
    {
        return $this->corpus->sources();
    }

    public function isAvailable(string $source): bool
    {
        return $this->corpus->isAvailable($source);
    }

    public function queuedJobs(): int
    {
        return $this->embedding->queuedJobs();
    }

    /**
     * Re-chunk the changed files of the given sources, drop the vanished ones and queue
     * embedding jobs for every chunk without a current vector.
     *
     * @param  list<string>  $sources
     */
    public function index(array $sources): DocsIndexRun
    {
        $changed = $unchanged = $removed = 0;
        $skipped = [];

        foreach ($sources as $source) {
            if (! $this->corpus->isAvailable($source)) {
                $skipped[] = $source;

                continue;
            }

            $stored = $this->index->documentHashes($source);
            $files = $this->corpus->files($source);

            foreach ($files as $file) {
                if ($this->indexFile($file, $stored)) {
                    $changed++;
                } else {
                    $unchanged++;
                }
            }

            $removed += $this->index->deleteMissing($source, array_map(fn (CorpusFile $file): string => $file->path, $files));
        }

        $ids = $this->index->staleChunkIds($this->embedding->modelLabel());
        $batches = array_chunk($ids, $this->embedding->batch());

        foreach ($batches as $chunkIds) {
            EmbedDocsBatchJob::dispatch($chunkIds)->onQueue($this->embedding->queue());
        }

        return new DocsIndexRun($changed, $unchanged, $removed, count($ids), count($batches), $this->embedding->batch(), $skipped);
    }

    /**
     * @param  array<string, string>  $stored  hashes of chunker version and content by path
     * @return bool whether the file was re-chunked
     */
    private function indexFile(CorpusFile $file, array $stored): bool
    {
        $markdown = $this->corpus->read($file);
        // The chunker version is part of the hash, so a change to the chunking rules re-chunks every file.
        $hash = hash('sha256', MarkdownChunker::VERSION."\n".$markdown);

        if (($stored[$file->path] ?? null) === $hash) {
            return false;
        }

        $chunked = $this->chunker->chunk($markdown, basename($file->path));

        $this->index->replaceDocument(
            new IndexedDocument($file->source, $file->path, $chunked->title, $hash, $chunked->chunks),
            $this->embedding->modelLabel(),
            CarbonImmutable::now(),
        );

        return true;
    }
}
