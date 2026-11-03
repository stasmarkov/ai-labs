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
use App\DTOs\DocsMatch;
use App\Enums\DocsAnswerOutcome;
use App\Exceptions\DocsAnswerUnavailableException;
use App\Exceptions\DocsSearchUnavailableException;
use Illuminate\Contracts\Support\Responsable;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;
use Throwable;

final readonly class DocsAnswerService
{
    public const NO_CONTEXT_MESSAGE = "I don't know: the indexed docs have nothing relevant to this question.";

    public const INPUT_REFUSAL = "The question looks like an attempt to change the assistant's instructions.";

    public function __construct(
        private PromptInjectionGuard $guard,
        private DocsAnswerContext $context,
        private CanaryGenerator $canaries,
    ) {}

    /**
     * Input guard, retrieval, relevance gate, then the model. A question the guard refuses, or that retrieval finds nothing
     * relevant for, is answered without a model call. Everything before the stream runs now, so a failure is a normal
     * HTTP error and Server-Timing covers it.
     *
     * @throws DocsAnswerUnavailableException when the question cannot be embedded.
     */
    public function ask(string $question): Responsable
    {
        $plan = $this->plan($question);
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
            ->usingProtocol(new DocsAnswerProtocol($recorder, $plan->sources, $plan->guard));
        $recorder->annotate($stream->invocationId, self::ids($plan->sources));

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
        $recorder->annotate($stream->invocationId, DocsAnswerProtocol::sourceIds($plan->sources));

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
    private function plan(string $question, ?string $model = null): DocsAnswerPlan
    {
        $startedAt = hrtime(true);
        $recorder = app(AiRunRecorder::class);
        $agent = new DocsAnswerAgent($this->canaries->generate(), $model);

        $pattern = $this->guard->match($question);

        if ($pattern !== null) {
            $invocationId = $this->recordOutcome($recorder, $agent, 'refused', $startedAt, 'input:'.$this->guardVersion().':'.$pattern);

            return new DocsAnswerPlan($agent, [], null, new DocsAnswerResult(DocsAnswerOutcome::RefusedInput, self::INPUT_REFUSAL, [], [], $invocationId), $pattern);
        }

        try {
            $retrieved = $this->context->retrieve($question);
        } catch (DocsSearchUnavailableException $e) {
            throw DocsAnswerUnavailableException::because($e);
        }

        $sources = $this->context->limit($retrieved, $question);

        if ($sources === []) {
            $invocationId = $this->recordOutcome($recorder, $agent, 'no_context', $startedAt, null, self::ids($retrieved));

            return new DocsAnswerPlan($agent, [], null, new DocsAnswerResult(DocsAnswerOutcome::NoContext, self::NO_CONTEXT_MESSAGE, [], [], $invocationId));
        }

        $guard = new OutputGuard(
            $agent->canary,
            $agent->systemPrompt(),
            [$question, ...array_map(fn (DocsMatch $match): string => $match->body, $sources)],
        );

        return new DocsAnswerPlan($agent, $sources, $guard);
    }

    /**
     * Writes the run row of an outcome without a model call and returns its invocation id, which the event carries so
     * the lab can look the run up.
     *
     * @param  list<int>|null  $retrieved
     */
    private function recordOutcome(AiRunRecorder $recorder, DocsAnswerAgent $agent, string $status, int $startedAt, ?string $reason, ?array $retrieved = null): string
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
