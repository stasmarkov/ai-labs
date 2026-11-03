<?php

declare(strict_types=1);

namespace App\Ai;

use App\DTOs\DocsMatch;
use Generator;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * The docs answer stream: the sources the model was given first, the SDK events as they come, the output guard on the
 * text so far, and the citations found in the finished answer last. Reasoning events (AI_THINK=true) are not sent: a
 * model's thinking often restates its instructions, and the output guard only checks the answer text.
 */
final class DocsAnswerProtocol extends SseProtocol
{
    public const OUTPUT_REFUSAL = 'The answer was withheld because it repeated the assistant\'s instructions.';

    public const ERROR_MESSAGE = 'The answer could not be generated.';

    /**
     * @param  list<DocsMatch>  $sources  in citation order; the first is source 1
     */
    public function __construct(
        AiRunRecorder $recorder,
        private readonly array $sources,
        private readonly OutputGuard $guard,
    ) {
        parent::__construct($recorder);
    }

    protected function parts(StreamableAgentResponse $response): Generator
    {
        yield $this->sourcesEvent();

        $text = '';
        $citations = null;

        foreach ($response as $event) {
            if ($event instanceof Error) {
                yield from $this->maskedErrorOnce();

                continue;
            }

            if ($event instanceof ReasoningStart || $event instanceof ReasoningDelta || $event instanceof ReasoningEnd) {
                continue;
            }

            if ($event instanceof TextDelta) {
                $this->recorder->firstToken($response->invocationId);
                $text .= $event->delta;
            }

            $refusal = $event instanceof TextDelta || $event instanceof StreamEnd ? self::refusalReason($this->guard, $text) : null;

            if ($refusal !== null) {
                // Returning abandons the SDK stream, so the run is finished here instead of by the SDK.
                $this->recorder->refused($response->invocationId, $refusal);

                yield [
                    'type' => 'refused',
                    'stage' => 'output',
                    'reason' => self::OUTPUT_REFUSAL,
                    'pattern' => null,
                    'replace' => true,
                ];

                return;
            }

            if ($event instanceof StreamEnd) {
                $citations = self::citations($text, $this->sources);
                // The SDK completes the run when it is asked for the event after this one, so the ids go in now.
                $this->recorder->annotate($response->invocationId, self::sourceIds($this->sources), array_column($citations['cited'], 'id'));
            }

            yield $event->toArray();
        }

        if ($citations !== null && ! $this->errored) {
            yield ['type' => 'citations', 'cited' => $citations['cited'], 'unknown' => $citations['unknown']];
        }
    }

    protected function maskedErrorParts(): Generator
    {
        yield ['type' => 'error', 'message' => self::ERROR_MESSAGE];
    }

    /**
     * The run's refusal reason when the text so far repeats the instructions or the canary, else null. The streamed
     * protocol and the drained answer() both ask this.
     */
    public static function refusalReason(?OutputGuard $guard, string $text): ?string
    {
        $leak = $guard?->leak($text);

        return $leak === null ? null : 'output:'.$leak;
    }

    /**
     * @param  list<DocsMatch>  $sources
     * @return list<int>
     */
    public static function sourceIds(array $sources): array
    {
        return array_map(fn (DocsMatch $match): int => $match->id, $sources);
    }

    /**
     * @return array{type: string, sources: list<array<string, mixed>>}
     */
    private function sourcesEvent(): array
    {
        $sources = [];

        foreach ($this->sources as $index => $match) {
            $sources[] = [
                'n' => $index + 1,
                'id' => $match->id,
                'source' => $match->source,
                'path' => $match->path,
                'heading' => $match->heading,
                'vector_similarity' => $match->vectorSimilarity === null ? null : round($match->vectorSimilarity, 6),
                'text_rank' => $match->textRank,
            ];
        }

        return ['type' => 'sources', 'sources' => $sources];
    }

    /**
     * @param  list<DocsMatch>  $sources
     * @return array{cited: list<array{n: int, id: int}>, unknown: list<int>}
     */
    public static function citations(string $answer, array $sources): array
    {
        return CitationParser::cite($answer, $sources);
    }

    private function maskedErrorOnce(): Generator
    {
        if (! $this->errored) {
            $this->errored = true;

            yield from $this->maskedErrorParts();
        }
    }
}
