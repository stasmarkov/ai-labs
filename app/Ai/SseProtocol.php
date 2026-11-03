<?php

declare(strict_types=1);

namespace App\Ai;

use Generator;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Protocols\StreamProtocol;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One "data: <event json>" line per SDK event, then "data: [DONE]", reporting the first text delta to the run recorder.
 */
class SseProtocol extends StreamProtocol
{
    public function __construct(protected readonly AiRunRecorder $recorder) {}

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function headers(): array
    {
        return [...parent::headers(), 'X-Accel-Buffering' => 'no'];
    }

    /**
     * Under Octane, Laravel hands a generator callback to the server client, and FrankenPHP's client calls it without
     * iterating, which sends an empty body. This wrapper drains the generator and flushes after every chunk.
     */
    #[\Override]
    public function response(StreamableAgentResponse $response): Response
    {
        $inner = parent::response($response);
        assert($inner instanceof StreamedResponse);
        $callback = $inner->getCallback();

        return new StreamedResponse(function () use ($callback): void {
            $chunks = $callback();

            if (! $chunks instanceof Generator) {
                return;
            }

            foreach ($chunks as $chunk) {
                echo $chunk;

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        }, $inner->getStatusCode(), $inner->headers->all());
    }

    protected function parts(StreamableAgentResponse $response): Generator
    {
        foreach ($response as $event) {
            if ($event instanceof TextDelta) {
                $this->recorder->firstToken($response->invocationId);
            }

            // A provider error event carries the provider's raw message: send the masked one instead, once.
            if ($event instanceof Error) {
                $this->errored = true;
                yield from $this->maskedErrorParts();

                continue;
            }

            /** @var StreamEvent $event */
            yield $event->toArray();
        }
    }

    protected function maskedErrorParts(): Generator
    {
        yield ['type' => 'error', 'message' => 'The description could not be generated.'];
    }

    #[\Override]
    protected function terminator(): string
    {
        return "data: [DONE]\n\n";
    }
}
