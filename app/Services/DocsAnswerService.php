<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Ai\CanaryGenerator;
use App\Ai\DocsAnswerAgent;
use App\Ai\DocsAnswerProtocol;
use App\Ai\OutputGuard;
use App\Ai\SseEventsResponse;
use App\DTOs\DocsAnswerPlan;
use App\DTOs\DocsAnswerResult;
use App\DTOs\DocsCacheCandidate;
use App\DTOs\DocsCacheHit;
use App\DTOs\DocsCacheVersions;
use App\DTOs\DocsMatch;
use App\Enums\DocsAnswerOutcome;
use App\Enums\DocsCacheMode;
use App\Exceptions\DocsAnswerUnavailableException;
use App\Exceptions\DocsSearchUnavailableException;
use Closure;
use Illuminate\Contracts\Support\Responsable;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;
use Throwable;

final readonly class DocsAnswerService
{
    public const NO_CONTEXT_MESSAGE = "I don't know: the indexed docs have nothing relevant to this question.";

    private const ORIGIN_API = 'api';

    private const ORIGIN_EVAL = 'eval';

    public const INPUT_REFUSAL = "The question looks like an attempt to change the assistant's instructions.";

    public function __construct(
        private PromptInjectionGuard $guard,
        private DocsAnswerContext $context,
        private CanaryGenerator $canaries,
        private DocsAnswerCache $cache,
    ) {}

    /**
     * Input guard, answer cache, retrieval, relevance gate, then the model. A question the guard refuses, or that
     * retrieval finds nothing relevant for, is answered without a model call, and so is one the cache can serve. The
     * guard always runs first. Everything before the stream runs now, so a failure is a normal HTTP error and
     * Server-Timing covers it.
     *
     * @param  bool  $useCache  false skips both lookups; a qualifying answer is still stored
     *
     * @throws DocsAnswerUnavailableException when the question cannot be embedded.
     */
    public function ask(string $question, bool $useCache = true): Responsable
    {
        $startedAt = hrtime(true);
        $mode = match (true) {
            ! config('ai.docs.cache.enabled') => DocsCacheMode::Off,
            $useCache => DocsCacheMode::Lookup,
            default => DocsCacheMode::Bypass,
        };
        $plan = $this->plan($question, null, $mode, $startedAt, self::ORIGIN_API);

        if ($plan->hit !== null) {
            return $this->serveHit($plan, $plan->hit, $startedAt);
        }

        $early = $plan->early;

        if ($early?->outcome === DocsAnswerOutcome::RefusedInput) {
            return new SseEventsResponse([
                ['type' => 'refused', 'stage' => 'input', 'reason' => self::INPUT_REFUSAL, 'pattern' => $plan->pattern, 'replace' => false, 'invocation_id' => $early->invocationId],
            ]);
        }

        if ($early !== null) {
            return new SseEventsResponse([['type' => 'no_context', 'message' => self::NO_CONTEXT_MESSAGE, 'invocation_id' => $early->invocationId]]);
        }

        $recorder = app(AiRunRecorder::class);
        $stream = $plan->agent->stream($plan->agent->promptFor($question, $plan->sources))
            ->usingProtocol(new DocsAnswerProtocol($recorder, $plan->sources, $plan->guard, $this->storeHook($question, $plan, $startedAt)));
        $recorder->annotate($stream->invocationId, self::ids($plan->sources), origin: self::ORIGIN_API);

        return $stream;
    }

    /**
     * The same steps as ask(), but the model answer is read whole instead of streamed, and the result is a value. A
     * model of the active provider other than its default can be named; the run row records the model used.
     *
     * @throws DocsAnswerUnavailableException when the question cannot be embedded. A failing model call comes back as a Failed result.
     */
    public function answer(string $question, ?string $model = null): DocsAnswerResult
    {
        $plan = $this->plan($question, $model);

        if ($plan->early !== null) {
            return $plan->early;
        }

        $recorder = app(AiRunRecorder::class);
        $agent = $plan->agent;
        $stream = $agent->stream($agent->promptFor($question, $plan->sources), model: $model);
        $recorder->annotate($stream->invocationId, DocsAnswerProtocol::sourceIds($plan->sources), origin: self::ORIGIN_EVAL);

        $text = '';

        try {
            foreach ($stream as $event) {
                if ($event instanceof Error) {
                    throw new RuntimeException(DocsAnswerProtocol::ERROR_MESSAGE);
                }

                if ($event instanceof TextDelta) {
                    $recorder->firstToken($stream->invocationId);
                    $text .= $event->delta;
                }

                if (! $event instanceof TextDelta && ! $event instanceof StreamEnd) {
                    continue;
                }

                $refusal = DocsAnswerProtocol::refusalReason($plan->guard, $text);

                if ($refusal !== null) {
                    $recorder->refused($stream->invocationId, $refusal);

                    return new DocsAnswerResult(DocsAnswerOutcome::RefusedOutput, DocsAnswerProtocol::OUTPUT_REFUSAL, $plan->sources, [], $stream->invocationId);
                }

                if ($event instanceof StreamEnd) {
                    $cited = array_column(DocsAnswerProtocol::citations($text, $plan->sources)['cited'], 'id');
                    $recorder->annotate($stream->invocationId, DocsAnswerProtocol::sourceIds($plan->sources), $cited);
                }
            }
        } catch (Throwable $e) {
            // An in-stream Error event abandons the SDK generator, so neither AgentStreamed nor AgentFailed fires. Finishing
            // the run here writes its failed row; it does nothing when the SDK already finished the run itself.
            $recorder->failed($stream->invocationId, $e);

            return new DocsAnswerResult(DocsAnswerOutcome::Failed, DocsAnswerProtocol::ERROR_MESSAGE, $plan->sources, [], $stream->invocationId);
        }

        return new DocsAnswerResult(DocsAnswerOutcome::Answered, $text, $plan->sources, $cited ?? [], $stream->invocationId);
    }

    /**
     * The steps ask() and answer() share: input guard, retrieval, relevance gate and budget, then the agent and output
     * guard. The two outcomes without a model call are recorded here and come back as the plan's early result.
     *
     * @throws DocsAnswerUnavailableException when the question cannot be embedded.
     */
    private function plan(string $question, ?string $model = null, DocsCacheMode $mode = DocsCacheMode::Off, ?int $startedAt = null, string $origin = self::ORIGIN_EVAL): DocsAnswerPlan
    {
        $startedAt ??= hrtime(true);
        $recorder = app(AiRunRecorder::class);
        $agent = new DocsAnswerAgent($this->canaries->generate(), $model);

        $pattern = $this->guard->match($question);

        if ($pattern !== null) {
            $invocationId = $this->recordOutcome($recorder, $agent, 'refused', $startedAt, 'input:'.$this->guardVersion().':'.$pattern, origin: $origin);

            return new DocsAnswerPlan($agent, [], null, new DocsAnswerResult(DocsAnswerOutcome::RefusedInput, self::INPUT_REFUSAL, [], [], $invocationId), $pattern);
        }

        $vector = null;
        $versions = $mode === DocsCacheMode::Off ? null : $this->versions($agent);

        try {
            if ($mode === DocsCacheMode::Lookup && $versions !== null) {
                $hit = $this->lookup(fn (): ?DocsCacheHit => $this->cache->lookupExact($question, $agent->promptVersion(), $agent->modelName(), $versions));

                if ($hit !== null) {
                    return new DocsAnswerPlan($agent, [], null, hit: $hit);
                }
            }

            $vector = $mode === DocsCacheMode::Off ? null : $this->context->embed($question);

            if ($mode === DocsCacheMode::Lookup && $versions !== null) {
                $hit = $this->lookup(fn (): ?DocsCacheHit => $this->cache->lookupSemantic($vector, $agent->promptVersion(), $agent->modelName(), $versions));

                if ($hit !== null) {
                    return new DocsAnswerPlan($agent, [], null, hit: $hit);
                }
            }

            $retrieved = $this->context->retrieve($question, $vector);
        } catch (DocsSearchUnavailableException $e) {
            throw DocsAnswerUnavailableException::because($e);
        }

        $sources = $this->context->limit($retrieved, $question);

        if ($sources === []) {
            $invocationId = $this->recordOutcome($recorder, $agent, 'no_context', $startedAt, null, self::ids($retrieved), $origin);

            return new DocsAnswerPlan($agent, [], null, new DocsAnswerResult(DocsAnswerOutcome::NoContext, self::NO_CONTEXT_MESSAGE, [], [], $invocationId), vector: $vector, versions: $versions);
        }

        $guard = new OutputGuard(
            $agent->canary,
            $agent->systemPrompt(),
            [$question, ...array_map(fn (DocsMatch $match): string => $match->body, $sources)],
        );

        return new DocsAnswerPlan($agent, $sources, $guard, vector: $vector, versions: $versions);
    }

    /**
     * The cache versions for this request, read once. A broken cache store is reported and leaves the request uncached.
     */
    private function versions(DocsAnswerAgent $agent): ?DocsCacheVersions
    {
        try {
            return $this->cache->versions($agent->promptVersion(), $agent->modelName());
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * A cache lookup that cannot fail the request: a broken cache store is reported and counts as a miss.
     *
     * @param  Closure(): ?DocsCacheHit  $lookup
     */
    private function lookup(Closure $lookup): ?DocsCacheHit
    {
        try {
            return $lookup();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Streams the stored answer in the order of the hit contract and writes the cached run row.
     */
    private function serveHit(DocsAnswerPlan $plan, DocsCacheHit $hit, int $startedAt): Responsable
    {
        $entry = $hit->entry;
        $latencyMs = intdiv(hrtime(true) - $startedAt, 1_000_000);
        $savedMs = max(0, $entry->sourceLatencyMs - $latencyMs);
        $similarity = round($hit->similarity, 6);

        $invocationId = app(AiRunRecorder::class)->recordCacheHit(
            class_basename($plan->agent),
            $entry->provider,
            $entry->versions->answerModel,
            $plan->agent->promptVersion(),
            $latencyMs,
            $hit->kind,
            $similarity,
            $entry->id,
            $savedMs,
            $entry->retrievedChunkIds,
            $entry->citedChunkIds,
        );
        $this->cache->recordHit($hit);

        $numbers = [];

        foreach ($entry->sources as $source) {
            $numbers[(int) $source['id']] = (int) $source['n'];
        }

        $cited = array_map(fn (int $id): array => ['n' => $numbers[$id], 'id' => $id], array_values(array_filter($entry->citedChunkIds, fn (int $id): bool => isset($numbers[$id]))));

        return new SseEventsResponse([
            ['type' => 'sources', 'sources' => $entry->sources],
            [
                'type' => 'cache_hit',
                'kind' => $hit->kind->value,
                'similarity' => $similarity,
                'cached_at' => $entry->createdAt->toIso8601String(),
                'saved_latency_ms' => $savedMs,
                'invocation_id' => $invocationId,
                'source_invocation_id' => $entry->sourceInvocationId,
            ],
            ['type' => 'text_delta', 'invocation_id' => $invocationId, 'delta' => $entry->answer],
            ['type' => 'citations', 'cited' => $cited, 'unknown' => []],
        ]);
    }

    /**
     * The step that offers a finished answer to the cache; DocsAnswerCache::store() refuses what must not be stored.
     * Null when the cache is off.
     *
     * @return Closure(string, string, list<int>): void|null
     */
    private function storeHook(string $question, DocsAnswerPlan $plan, int $startedAt): ?Closure
    {
        if ($plan->vector === null || $plan->versions === null) {
            return null;
        }

        $recorder = app(AiRunRecorder::class);

        return function (string $invocationId, string $answer, array $citedIds) use ($question, $plan, $startedAt, $recorder): void {
            $this->cache->store(new DocsCacheCandidate(
                question: $question,
                vector: $plan->vector,
                outcome: DocsAnswerOutcome::Answered,
                answer: $answer,
                sources: DocsAnswerProtocol::sourcesPayload($plan->sources),
                citedChunkIds: $citedIds,
                retrievedChunkIds: self::ids($plan->sources),
                provider: $plan->agent->providerName(),
                versions: $plan->versions,
                sourceInvocationId: $invocationId,
                sourceLatencyMs: $recorder->latencyOf($invocationId) ?? intdiv(hrtime(true) - $startedAt, 1_000_000),
                aborted: connection_aborted() === 1,
            ));
        };
    }

    /**
     * Writes the run row of an outcome without a model call and returns its invocation id, which the event carries so
     * the lab can look the run up.
     *
     * @param  list<int>|null  $retrieved
     */
    private function recordOutcome(AiRunRecorder $recorder, DocsAnswerAgent $agent, string $status, int $startedAt, ?string $reason, ?array $retrieved = null, ?string $origin = null): string
    {
        return $recorder->recordOutcome(
            $status,
            class_basename($agent),
            $agent->providerName(),
            $agent->modelName(),
            $agent->promptVersion(),
            intdiv(hrtime(true) - $startedAt, 1_000_000),
            $reason,
            $retrieved,
            $origin,
        );
    }

    /**
     * The guard file's name without extension, such as "prompt-injection.v1".
     */
    private function guardVersion(): string
    {
        return pathinfo((string) config('ai.docs.answer.guard'), PATHINFO_FILENAME);
    }

    /**
     * @param  list<DocsMatch>  $matches
     * @return list<int>
     */
    private static function ids(array $matches): array
    {
        return array_map(fn (DocsMatch $match): int => $match->id, $matches);
    }
}
