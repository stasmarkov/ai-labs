<?php

declare(strict_types=1);

namespace App\Ai;

use Generator;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Protocols\StreamProtocol;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * One "data: <event json>" line per SDK event, then "data: [DONE]", reporting the first text delta to the run recorder.
 */
class SseProtocol extends StreamProtocol
{
    /** @var int text deltas sent so far; one delta is counted as one output token when a run is stopped */
    protected int $textDeltas = 0;

    /** An SSE comment line: a write for the abort check that clients ignore (the page's parser reads "data:" lines only). */
    public const KEEPALIVE = ": keepalive\n\n";

    /** @var bool true once the SDK has produced an event, i.e. the run has started and has a recorder entry */
    protected bool $eventsRead = false;

    /** @var TextUsage|null the combined usage of the StreamEnd events seen; set means the model has finished */
    protected ?TextUsage $finishedUsage = null;

    /** @var Error|null the provider error event seen, if any */
    protected ?Error $errorEvent = null;

    protected readonly AbortWatcher $watcher;

    public function __construct(protected readonly AiRunRecorder $recorder, ?AbortWatcher $watcher = null)
    {
        $this->watcher = $watcher ?? app(AbortWatcher::class);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function headers(): array
    {
        return [...parent::headers(), 'X-Accel-Buffering' => 'no'];
    }

    /**
     * Drains the chunk generator itself and flushes after every chunk, which works under php-fpm and under Octane alike
     * (FrankenPHP's client calls a generator callback without iterating it, which sends an empty body). After each flush
     * it asks the abort watcher; a stop ends the loop and sends nothing more. The run is then recorded by what had
     * happened: stopped, or completed when the model had already ended, or failed after a provider error.
     *
     * Guarantee for subclasses: the check runs after every chunk and before the generator is resumed, so code that runs
     * on resuming (such as the docs answer's cache store step) never runs for a client that has gone.
     */
    #[\Override]
    public function response(StreamableAgentResponse $response): Response
    {
        return new StreamedResponse(function () use ($response): void {
            // Without this PHP stops the script at the first write after a disconnect, and the stop could not be recorded.
            $previous = ignore_user_abort(true);
            $chunks = $this->chunks($response);

            try {
                foreach ($chunks as $chunk) {
                    echo $chunk;

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();

                    if ($this->watcher->aborted()) {
                        $this->recordAbort($response);

                        break;
                    }
                }
            } finally {
                // Destroying the generators closes the SDK stream and with it the provider's HTTP body.
                unset($chunks);
                ignore_user_abort($previous === 1);
            }
        }, 200, [...$this->headers()]);
    }

    /**
     * Records how a run that the client left ended. After the model's StreamEnd or a provider error the run is not
     * stopped, only unreported: the SDK would finish it when the generator is resumed, which a stop does not do.
     */
    protected function recordAbort(StreamableAgentResponse $response): void
    {
        $id = $response->invocationId;

        if (! $this->eventsRead) {
            $this->recordStoppedBeforeRun($response);
        } elseif ($this->finishedUsage !== null) {
            $this->recorder->completed($id, $this->finishedUsage->inputTokens, $this->finishedUsage->outputTokens);
        } elseif ($this->errorEvent !== null || $this->exception !== null) {
            $this->recorder->failed($id, $this->exception ?? new StreamErrorException($this->errorEvent));
        } else {
            $this->recorder->stopped($id, $this->textDeltas === 0 ? null : $this->textDeltas);
        }
    }

    /**
     * The client left before the SDK started the run, so the recorder has no entry for it. Streams that know the
     * request's metadata record the row here; the default records nothing.
     */
    protected function recordStoppedBeforeRun(StreamableAgentResponse $response): void {}

    /**
     * Notes what the run-state of an SDK event means for recording an abort.
     */
    protected function observe(StreamEvent $event): void
    {
        $this->eventsRead = true;

        if ($event instanceof StreamEnd) {
            $this->finishedUsage = $this->finishedUsage === null ? $event->usage : $this->finishedUsage->add($event->usage);
        } elseif ($event instanceof Error) {
            $this->errorEvent = $event;
        }
    }

    /**
     * The chunks of the stream, the same as Laravel\Ai\Streaming\Protocols\StreamProtocol::response() builds inside its
     * callback (laravel/ai v1.3.0). That method streams through response()->stream(), which gives no way to look at
     * the client between chunks, so the loop is forked here. tests/Unit/SseProtocolForkTest.php hashes the vendor
     * method and fails when an SDK upgrade changes it, so the fork is reviewed against the new version.
     *
     * @return Generator<int, string>
     */
    private function chunks(StreamableAgentResponse $response): Generator
    {
        try {
            foreach ($this->parts($response) as $part) {
                yield is_string($part) ? $part : $this->encode($part);
            }
        } catch (Throwable $e) {
            $this->exception = $e;

            // A stream error exception carries a provider error the stream already surfaced, so only report anything else.
            if (! $e instanceof StreamErrorException) {
                report($e);
            }

            // The response is already streaming, so surface a masked terminal error part instead of re-throwing, unless one was sent.
            if (! $this->errored) {
                foreach ($this->maskedErrorParts() as $part) {
                    yield $this->encode($part);
                }
            }
        }

        yield $this->terminator();
    }

    protected function parts(StreamableAgentResponse $response): Generator
    {
        foreach ($response as $event) {
            $this->observe($event);

            if ($event instanceof TextDelta) {
                $this->recorder->firstToken($response->invocationId);
                $this->textDeltas++;
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
