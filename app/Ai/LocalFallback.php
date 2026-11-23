<?php

declare(strict_types=1);

namespace App\Ai;

use Closure;
use Generator;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextStart;
use Throwable;

/**
 * Runs an agent call on the smaller local model when the main model fails, in one place for every agent. One fallback
 * attempt per request, on the same provider; a policy that is off leaves the call exactly as it was. A streamed call
 * whose first content event comes later than the policy's budget counts as busy and moves over too; non-streamed
 * calls have no first token, so only errors trigger them (the budget never applies to them).
 */
final class LocalFallback
{
    /**
     * A non-streamed call. The attempt runs on the main model (null) and, when that fails with a trigger, once on the
     * fallback model. When both fail the main failure is thrown, as without a fallback; the trail says what was tried.
     *
     * @template T
     *
     * @param  Closure(?string): T  $attempt  runs the call on the given model, or on the main one when null
     * @return T
     */
    public function prompt(FallbackPolicy $policy, Closure $attempt, FallbackTrail $trail): mixed
    {
        try {
            return $attempt(null);
        } catch (Throwable $failure) {
            $reason = $policy->enabled() ? FallbackPolicy::reasonFor($failure) : null;

            if ($reason === null) {
                throw $failure;
            }
        }

        $trail->record((string) $policy->model, $reason);

        try {
            return $attempt($policy->model);
        } catch (Throwable) {
            throw $failure;
        }
    }

    /**
     * A streamed call. Events that only open the stream (and an in-stream provider error, which is no content) are held until the first content event; a failure with a
     * trigger before that moves the request to a fresh stream on the fallback model, so nothing of the main attempt
     * reaches the client. The first content event arriving after the budget abandons the main stream (reason busy) and
     * its event is dropped. After the first content event (text, reasoning or a tool call) a failure ends the stream
     * as it did before. The run row is the fallback attempt's, carrying the main model and the reason.
     *
     * @param  Closure(?string): StreamableAgentResponse  $open  opens the stream on the given model, or on the main one when null
     */
    public function stream(FallbackPolicy $policy, string $mainModel, Closure $open): StreamableAgentResponse
    {
        $main = $open(null);

        if (! $policy->enabled()) {
            return $main;
        }

        $recorder = app(AiRunRecorder::class);
        $recorder->holdFailure($main->invocationId);

        $outer = null;
        $outer = new StreamableAgentResponse(
            $main->invocationId,
            function () use ($main, $policy, $mainModel, $open, $recorder, &$outer): Generator {
                $held = [];
                $sent = false;
                $startedAt = hrtime(true);
                $budget = app(FirstTokenBudget::class);

                if ($policy->afterMs !== null) {
                    $budget->arm($mainModel, $policy->afterMs);
                }

                try {
                    foreach ($main as $event) {
                        if (! $sent && $this->opensStream($event)) {
                            $held[] = $event;

                            continue;
                        }

                        if (! $sent) {
                            if ($policy->afterMs !== null && intdiv(hrtime(true) - $startedAt, 1_000_000) > $policy->afterMs) {
                                throw new FirstTokenBudgetExceeded($policy->afterMs);
                            }

                            $sent = true;
                            $budget->disarm();
                            yield from $held;
                            $held = [];
                        }

                        yield $event;
                    }

                    yield from $held;
                    $recorder->releaseFailure($main->invocationId);

                    return;
                } catch (Throwable $failure) {
                    $reason = $sent ? null : ($failure instanceof FirstTokenBudgetExceeded ? 'busy' : FallbackPolicy::reasonFor($failure));

                    if ($reason === null) {
                        $recorder->releaseFailure($main->invocationId);

                        throw $failure;
                    }
                } finally {
                    $budget->disarm();
                }

                $fallback = $open($policy->model);
                $recorder->fallbackTo($main->invocationId, $fallback->invocationId, $mainModel, $reason);
                $outer->invocationId = $fallback->invocationId;
                $fallback->then(fn (StreamedAgentResponse $response): StreamableAgentResponse => $outer->adoptStateFrom($response));

                yield from $fallback;
            },
            new Meta,
        );

        $main->then(fn (StreamedAgentResponse $response): StreamableAgentResponse => $outer->adoptStateFrom($response));

        return $outer;
    }

    private function opensStream(StreamEvent $event): bool
    {
        return $event instanceof StreamStart || $event instanceof TextStart || $event instanceof ReasoningStart || $event instanceof Error;
    }
}
