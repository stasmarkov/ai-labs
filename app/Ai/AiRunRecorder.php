<?php

declare(strict_types=1);

namespace App\Ai;

use App\Contracts\AiRunRepositoryInterface;
use App\DTOs\AiRunRecord;
use App\Enums\DocsCacheKind;
use Illuminate\Support\Str;
use Throwable;

/**
 * Collects the timing of each streamed run and writes one ai_runs row when it ends.
 * Bound with scoped(), so under Octane its state is dropped after every request.
 */
final class AiRunRecorder
{
    private const MAX_MESSAGE_LENGTH = 1000;

    private const MAX_REASON_LENGTH = 120;

    /** @var array<string, array{agent: string, provider: string, model: string, promptVersion: ?string, inputs: ?int, startedAt: int, firstTokenAt: ?int, retrieved: ?list<int>, cited: ?list<int>, origin: ?string}> */
    private array $runs = [];

    /** @var array<string, array{retrieved: list<int>, cited: ?list<int>, origin: ?string}> */
    private array $pendingAnnotations = [];

    private ?string $embeddingsAgent = null;

    private const KEPT_LATENCIES = 50;

    /** @var array<string, int> latency_ms written with a finished run, by invocation id; the newest few are kept */
    private array $finishedLatencies = [];

    public function __construct(private readonly AiRunRepositoryInterface $repository) {}

    /**
     * The latency_ms the finished run was logged with (its stream start to its end), or null for a run that has not
     * finished here. The answer cache stores it as the source latency of an entry.
     */
    public function latencyOf(string $invocationId): ?int
    {
        return $this->finishedLatencies[$invocationId] ?? null;
    }

    /**
     * Names the agent of the next embeddings call, so search queries are told apart from indexing batches.
     */
    public function nextEmbeddingsAgent(?string $agent): void
    {
        $this->embeddingsAgent = $agent;
    }

    public function embeddingsAgent(string $default): string
    {
        return $this->embeddingsAgent ?? $default;
    }

    public function started(string $invocationId, string $agent, string $provider, string $model, ?string $promptVersion, ?int $inputs = null): void
    {
        $this->runs[$invocationId] = [
            'agent' => $agent,
            'provider' => $provider,
            'model' => $model,
            'promptVersion' => $promptVersion,
            'inputs' => $inputs,
            'startedAt' => hrtime(true),
            'firstTokenAt' => null,
            'retrieved' => $this->pendingAnnotations[$invocationId]['retrieved'] ?? null,
            'cited' => $this->pendingAnnotations[$invocationId]['cited'] ?? null,
            'origin' => $this->pendingAnnotations[$invocationId]['origin'] ?? null,
        ];
        unset($this->pendingAnnotations[$invocationId]);
    }

    /**
     * Attaches the retrieved and cited chunk ids to a run; they are written with the row when it finishes. A run that has
     * not started yet (the SDK starts it when the stream is first read) takes them when it does.
     *
     * @param  list<int>  $retrieved
     * @param  list<int>|null  $cited
     * @param  string|null  $origin  "api" or "eval"; an annotation without one keeps the origin already set
     */
    public function annotate(string $invocationId, array $retrieved, ?array $cited = null, ?string $origin = null): void
    {
        if (! isset($this->runs[$invocationId])) {
            $origin ??= $this->pendingAnnotations[$invocationId]['origin'] ?? null;
            $this->pendingAnnotations[$invocationId] = ['retrieved' => $retrieved, 'cited' => $cited, 'origin' => $origin];

            return;
        }

        $this->runs[$invocationId]['origin'] = $origin ?? $this->runs[$invocationId]['origin'];
        $this->runs[$invocationId]['retrieved'] = $retrieved;
        $this->runs[$invocationId]['cited'] = $cited ?? $this->runs[$invocationId]['cited'];
    }

    /**
     * Finishes a started run the output guard abandoned, so it is not left without a row.
     */
    public function refused(string $invocationId, string $reason): void
    {
        $this->finish($invocationId, 'refused', refusalReason: $reason);
    }

    /**
     * Writes the row of a request that ended without a model call ("refused" or "no_context"). Returns its invocation id.
     *
     * @param  list<int>|null  $retrieved
     */
    public function recordOutcome(
        string $status,
        string $agent,
        string $provider,
        string $model,
        ?string $promptVersion,
        int $latencyMs,
        ?string $refusalReason = null,
        ?array $retrieved = null,
        ?string $origin = null,
    ): string {
        $invocationId = (string) Str::uuid();

        rescue(fn () => $this->repository->save(new AiRunRecord(
            invocationId: $invocationId,
            agent: $agent,
            provider: $provider,
            model: $model,
            promptVersion: $promptVersion,
            tokensIn: null,
            tokensOut: null,
            ttftMs: null,
            latencyMs: $latencyMs,
            status: $status,
            retrievedChunkIds: $retrieved,
            refusalReason: $refusalReason === null ? null : mb_substr($refusalReason, 0, self::MAX_REASON_LENGTH),
            origin: $origin,
        )));

        return $invocationId;
    }

    /**
     * Writes the row of a cached answer that was served. Returns its invocation id.
     *
     * @param  list<int>  $retrieved
     * @param  list<int>  $cited
     */
    public function recordCacheHit(
        string $agent,
        string $provider,
        string $model,
        ?string $promptVersion,
        int $latencyMs,
        DocsCacheKind $kind,
        float $similarity,
        int $entryId,
        int $savedLatencyMs,
        array $retrieved,
        array $cited,
    ): string {
        $invocationId = (string) Str::uuid();

        rescue(fn () => $this->repository->save(new AiRunRecord(
            invocationId: $invocationId,
            agent: $agent,
            provider: $provider,
            model: $model,
            promptVersion: $promptVersion,
            tokensIn: null,
            tokensOut: null,
            ttftMs: null,
            latencyMs: $latencyMs,
            status: 'cached',
            retrievedChunkIds: $retrieved,
            citedChunkIds: $cited,
            cacheKind: $kind->value,
            cacheSimilarity: $similarity,
            cacheEntryId: $entryId,
            savedLatencyMs: $savedLatencyMs,
            origin: 'api',
        )));

        return $invocationId;
    }

    /**
     * Writes the row of a model call that was not streamed. Returns its invocation id, the SDK's when it has one.
     *
     * @param  list<int>  $retrieved
     * @param  list<int>|null  $cited
     */
    public function recordRun(
        string $status,
        string $agent,
        string $provider,
        string $model,
        ?string $promptVersion,
        int $latencyMs,
        array $retrieved,
        ?array $cited = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?string $invocationId = null,
        ?Throwable $exception = null,
        ?string $refusalReason = null,
    ): string {
        $invocationId ??= (string) Str::uuid();

        rescue(fn () => $this->repository->save(new AiRunRecord(
            invocationId: $invocationId,
            agent: $agent,
            provider: $provider,
            model: $model,
            promptVersion: $promptVersion,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            ttftMs: null,
            latencyMs: $latencyMs,
            status: $status,
            errorClass: $exception === null ? null : $exception::class,
            errorMessage: $exception === null ? null : mb_substr($exception->getMessage(), 0, self::MAX_MESSAGE_LENGTH),
            retrievedChunkIds: $retrieved,
            citedChunkIds: $cited,
            refusalReason: $refusalReason === null ? null : mb_substr($refusalReason, 0, self::MAX_REASON_LENGTH),
        )));

        return $invocationId;
    }

    public function firstToken(string $invocationId): void
    {
        if (isset($this->runs[$invocationId]) && $this->runs[$invocationId]['firstTokenAt'] === null) {
            $this->runs[$invocationId]['firstTokenAt'] = hrtime(true);
        }
    }

    public function completed(string $invocationId, ?int $tokensIn, ?int $tokensOut): void
    {
        $this->finish($invocationId, 'completed', $tokensIn, $tokensOut);
    }

    public function failed(string $invocationId, Throwable $exception): void
    {
        $this->finish(
            $invocationId,
            'failed',
            errorClass: $exception::class,
            errorMessage: mb_substr($exception->getMessage(), 0, self::MAX_MESSAGE_LENGTH),
        );
    }

    private function finish(
        string $invocationId,
        string $status,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?string $errorClass = null,
        ?string $errorMessage = null,
        ?string $refusalReason = null,
    ): void {
        $run = $this->runs[$invocationId] ?? null;

        if ($run === null) {
            return;
        }

        unset($this->runs[$invocationId]);

        $latencyMs = $this->millisecondsSince($run['startedAt'], hrtime(true));
        $this->finishedLatencies = array_slice([...$this->finishedLatencies, $invocationId => $latencyMs], -self::KEPT_LATENCIES, null, true);

        // The SDK finishes a run inside the stream, before "[DONE]": a failed insert is reported, not sent to the client.
        rescue(fn () => $this->repository->save(new AiRunRecord(
            invocationId: $invocationId,
            agent: $run['agent'],
            provider: $run['provider'],
            model: $run['model'],
            promptVersion: $run['promptVersion'],
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            ttftMs: $run['firstTokenAt'] === null ? null : $this->millisecondsSince($run['startedAt'], $run['firstTokenAt']),
            latencyMs: $latencyMs,
            status: $status,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
            inputs: $run['inputs'],
            retrievedChunkIds: $run['retrieved'],
            citedChunkIds: $run['cited'],
            refusalReason: $refusalReason === null ? null : mb_substr($refusalReason, 0, self::MAX_REASON_LENGTH),
            origin: $run['origin'],
        )));
    }

    private function millisecondsSince(int $start, int $end): int
    {
        return intdiv($end - $start, 1_000_000);
    }
}
