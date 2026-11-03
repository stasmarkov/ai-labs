<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Ai\CanaryGenerator;
use App\Ai\DocsAnswerAgent;
use App\Ai\DocsAnswerProtocol;
use App\Ai\OutputGuard;
use App\Ai\SseEventsResponse;
use App\DTOs\DocsMatch;
use App\Exceptions\DocsAnswerUnavailableException;
use App\Exceptions\DocsSearchUnavailableException;
use Illuminate\Contracts\Support\Responsable;

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
        $startedAt = hrtime(true);
        $recorder = app(AiRunRecorder::class);
        $agent = new DocsAnswerAgent($this->canaries->generate());

        $pattern = $this->guard->match($question);

        if ($pattern !== null) {
            $invocationId = $this->recordOutcome($recorder, $agent, 'refused', $startedAt, 'input:'.$this->guardVersion().':'.$pattern);

            return new SseEventsResponse([
                ['type' => 'refused', 'stage' => 'input', 'reason' => self::INPUT_REFUSAL, 'pattern' => $pattern, 'replace' => false, 'invocation_id' => $invocationId],
            ]);
        }

        try {
            $retrieved = $this->context->retrieve($question);
        } catch (DocsSearchUnavailableException $e) {
            throw DocsAnswerUnavailableException::because($e);
        }

        $sources = $this->context->limit($retrieved, $question);

        if ($sources === []) {
            $invocationId = $this->recordOutcome($recorder, $agent, 'no_context', $startedAt, null, self::ids($retrieved));

            return new SseEventsResponse([['type' => 'no_context', 'message' => self::NO_CONTEXT_MESSAGE, 'invocation_id' => $invocationId]]);
        }

        $guard = new OutputGuard(
            $agent->canary,
            $agent->systemPrompt(),
            [$question, ...array_map(fn (DocsMatch $match): string => $match->body, $sources)],
        );

        $stream = $agent->stream($agent->promptFor($question, $sources))
            ->usingProtocol(new DocsAnswerProtocol($recorder, $sources, $guard));
        $recorder->annotate($stream->invocationId, self::ids($sources));

        return $stream;
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
