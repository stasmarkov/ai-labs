<?php

declare(strict_types=1);

namespace App\Ai;

use Closure;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * The first-byte deadline for the main model's streamed chat request while it is still silent. LocalFallback arms it
 * for the main model before it reads the stream and disarms it at the first content event; while armed, the HTTP
 * client middleware lowers the request's timeout to the budget and restores the feature's timeout as the read timeout
 * once headers are in, and a timeout inside the budget surfaces as FirstTokenBudgetExceeded.
 */
final class FirstTokenBudget
{
    private ?string $model = null;

    private int $afterMs = 0;

    public function arm(string $model, int $afterMs): void
    {
        $this->model = $model;
        $this->afterMs = $afterMs;
    }

    public function disarm(): void
    {
        $this->model = null;
    }

    /**
     * A Guzzle middleware for the shared HTTP client.
     */
    public function middleware(): Closure
    {
        return fn (callable $handler): Closure => function (RequestInterface $request, array $options) use ($handler) {
            if (! $this->appliesTo($request)) {
                return $handler($request, $options);
            }

            $afterMs = $this->afterMs;
            $options['read_timeout'] ??= $options['timeout'] ?? 60;
            $options['timeout'] = $afterMs / 1000;
            $startedAt = hrtime(true);

            try {
                return $handler($request, $options)->then(null, fn (Throwable $failure) => throw $this->exceeded($failure, $afterMs, $startedAt));
            } catch (Throwable $failure) {
                throw $this->exceeded($failure, $afterMs, $startedAt);
            }
        };
    }

    private function exceeded(Throwable $failure, int $afterMs, int $startedAt): Throwable
    {
        $timedOut = FallbackPolicy::timedOut($failure) || $this->streamOpenTimedOut($failure, $afterMs, $startedAt);

        return $timedOut ? new FirstTokenBudgetExceeded($afterMs, $failure) : $failure;
    }

    /**
     * Guzzle's stream handler (used for streamed chat requests) reports a timeout while opening the stream as a bare
     * "HTTP request failed!" with no mention of a timeout, so it counts only when the budget has really run out.
     */
    private function streamOpenTimedOut(Throwable $failure, int $afterMs, int $startedAt): bool
    {
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        return $elapsedMs >= $afterMs * 0.9 && str_contains($failure->getMessage(), 'HTTP request failed!');
    }

    private function appliesTo(RequestInterface $request): bool
    {
        if ($this->model === null || ! str_ends_with($request->getUri()->getPath(), '/api/chat')) {
            return false;
        }

        $body = $request->getBody();
        $payload = json_decode((string) $body, true);

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return is_array($payload) && ($payload['model'] ?? null) === $this->model;
    }
}
