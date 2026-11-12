<?php

declare(strict_types=1);

namespace App\Ai;

use Generator;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolResult;

/**
 * The assistant stream: the SDK events as the chat sends them, plus the tool loop. A tool call goes out as
 * "tool_call", its result as "tool_result", and a call the tool rejected or that failed as "tool_error" (the error
 * code, with the field errors when the arguments were invalid). Reasoning events are not sent; each is replaced by an
 * SSE comment line so the abort check still runs.
 */
final class AssistantProtocol extends SseProtocol
{
    public const ERROR_MESSAGE = 'The answer could not be generated.';

    protected function parts(StreamableAgentResponse $response): Generator
    {
        foreach ($response as $event) {
            $this->observe($event);

            if ($event instanceof Error) {
                yield from $this->maskedErrorOnce();

                continue;
            }

            if ($event instanceof ReasoningStart || $event instanceof ReasoningDelta || $event instanceof ReasoningEnd) {
                yield self::KEEPALIVE;

                continue;
            }

            if ($event instanceof TextDelta) {
                $this->recorder->firstToken($response->invocationId);
                $this->textDeltas++;
            }

            yield $event instanceof ToolResult ? $this->toolResultPart($event) : $event->toArray();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toolResultPart(ToolResult $event): array
    {
        $part = $event->toArray();
        $decoded = is_string($event->toolResult->result) ? json_decode($event->toolResult->result, true) : null;
        $error = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : null;

        if ($event->successful && $error === null) {
            return $part;
        }

        return [
            'id' => $part['id'],
            'invocation_id' => $part['invocation_id'],
            'type' => 'tool_error',
            'tool_id' => $part['tool_id'],
            'tool_name' => $part['tool_name'],
            'error' => $error ?? 'failed',
            'final' => is_array($decoded) && ($decoded['final'] ?? false) === true,
            'errors' => is_array($decoded) && is_array($decoded['errors'] ?? null) ? $decoded['errors'] : null,
            'timestamp' => $part['timestamp'],
        ];
    }

    protected function maskedErrorParts(): Generator
    {
        yield ['type' => 'error', 'message' => self::ERROR_MESSAGE];
    }

    private function maskedErrorOnce(): Generator
    {
        if (! $this->errored) {
            $this->errored = true;

            yield from $this->maskedErrorParts();
        }
    }
}
