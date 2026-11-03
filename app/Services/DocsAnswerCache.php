<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DocsAnswerCacheRepositoryInterface;
use App\DTOs\DocsCacheCandidate;
use App\DTOs\DocsCacheHit;
use App\DTOs\DocsCacheNewEntry;
use App\DTOs\DocsCacheVersions;
use App\Enums\DocsAnswerOutcome;
use App\Enums\DocsCacheKind;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whole-answer cache for the docs chatbot: an exact key in the cache store, the nearest question by cosine in the table.
 * An entry is served only while its prompt version, answer model, embedding model and index version are the current ones.
 */
final readonly class DocsAnswerCache
{
    private const KEY_PREFIX = 'docs-answer:exact:';

    public function __construct(
        private DocsAnswerCacheRepositoryInterface $entries,
        private DocsIndexVersion $indexVersion,
        private DocsEmbeddingService $embedding,
    ) {}

    /**
     * Lowercase, trimmed, whitespace runs collapsed, trailing runs of ? ! and . removed. Used for the exact key only.
     */
    public static function normalize(string $question): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($question)));

        return (string) preg_replace('/[\s?!.]+$/u', '', $text);
    }

    public function indexVersion(): string
    {
        return $this->indexVersion->current();
    }

    /**
     * The four versions as they are right now. DocsAnswerService reads them once per request and carries them from the
     * lookups to store(), so an answer is stored under the versions it was planned with.
     */
    public function versions(string $promptVersion, string $answerModel): DocsCacheVersions
    {
        return new DocsCacheVersions($promptVersion, $answerModel, $this->embedding->modelLabel(), $this->indexVersion->current());
    }

    public function lookupExact(string $question, string $promptVersion, string $answerModel, ?DocsCacheVersions $versions = null): ?DocsCacheHit
    {
        $versions ??= $this->versions($promptVersion, $answerModel);
        $key = $this->exactKey($question, $versions);
        $id = $this->exactStore()->get($key);

        if ($id === null) {
            return null;
        }

        $entry = $this->entries->findCurrent((int) $id, $versions, $this->notBefore());

        if ($entry === null) {
            $this->exactStore()->forget($key);

            return null;
        }

        return new DocsCacheHit($entry, DocsCacheKind::Exact, 1.0);
    }

    /**
     * @param  list<float>  $vector
     */
    public function lookupSemantic(array $vector, string $promptVersion, string $answerModel, ?DocsCacheVersions $versions = null): ?DocsCacheHit
    {
        $nearest = $this->entries->nearestCurrent(
            $vector,
            $versions ?? $this->versions($promptVersion, $answerModel),
            $this->notBefore(),
            (int) config('ai.embeddings.ef_search'),
        );

        if ($nearest === null || $nearest['similarity'] < (float) config('ai.docs.cache.min_similarity')) {
            return null;
        }

        return new DocsCacheHit($nearest['entry'], DocsCacheKind::Semantic, $nearest['similarity']);
    }

    /**
     * Counts a served hit. A failure is reported; the hit is still served.
     */
    public function recordHit(DocsCacheHit $hit): void
    {
        try {
            $this->entries->recordHit($hit->entry->id);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Stores a completed, cited, not aborted answer and its exact key under the versions the candidate was planned
     * with; the index version is not read again. Anything else is refused, and so is every answer while any chunk lacks
     * a vector for the current embedding model: the retrieval behind it ran on a half-embedded index, and the entry
     * would be filed under a version that the finished index no longer has. A failure is reported, never thrown;
     * returns whether the entry was stored.
     */
    public function store(DocsCacheCandidate $candidate): bool
    {
        if ($candidate->outcome !== DocsAnswerOutcome::Answered
            || $candidate->citedChunkIds === []
            || $candidate->aborted
            || trim($candidate->answer) === '') {
            return false;
        }

        try {
            if ($this->indexVersion->hasPendingEmbeddings()) {
                return false;
            }

            $versions = $candidate->versions;
            $id = $this->entries->upsert(new DocsCacheNewEntry(
                question: $candidate->question,
                questionHash: $this->questionHash($candidate->question),
                vector: $candidate->vector,
                answer: $candidate->answer,
                sources: $candidate->sources,
                citedChunkIds: $candidate->citedChunkIds,
                retrievedChunkIds: $candidate->retrievedChunkIds,
                provider: $candidate->provider,
                versions: $versions,
                sourceInvocationId: $candidate->sourceInvocationId,
                sourceLatencyMs: $candidate->sourceLatencyMs,
            ));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        try {
            $this->exactStore()->put($this->exactKey($candidate->question, $versions), $id, $this->ttlSeconds());
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }

    /**
     * @return array{current: int, stale: int}
     */
    public function countEntries(string $promptVersion, string $answerModel): array
    {
        return $this->entries->countByCurrency($this->versions($promptVersion, $answerModel), $this->notBefore());
    }

    /**
     * Deletes the stale and expired entries and their exact keys; returns how many entries went.
     */
    public function prune(string $promptVersion, string $answerModel): int
    {
        $deleted = $this->entries->deleteNotCurrent($this->versions($promptVersion, $answerModel), $this->notBefore());

        foreach ($deleted as $entry) {
            $this->exactStore()->forget($this->exactKey($entry->question, $entry->versions));
        }

        return count($deleted);
    }

    private function exactKey(string $question, DocsCacheVersions $versions): string
    {
        return self::KEY_PREFIX.hash('sha256', implode("\n", [
            self::normalize($question),
            $versions->promptVersion,
            $versions->answerModel,
            $versions->embeddingModel,
            $versions->indexVersion,
        ]));
    }

    private function questionHash(string $question): string
    {
        return hash('sha256', self::normalize($question));
    }

    private function ttlSeconds(): int
    {
        return (int) config('ai.docs.cache.ttl_hours') * 3600;
    }

    private function notBefore(): CarbonImmutable
    {
        return CarbonImmutable::now()->subSeconds($this->ttlSeconds());
    }

    private function exactStore(): Repository
    {
        return Cache::store((string) config('ai.docs.cache.store'));
    }
}
