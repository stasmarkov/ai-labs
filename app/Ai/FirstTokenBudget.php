<?php

declare(strict_types=1);

namespace App\Ai;

use Closure;
use GuzzleHttp\Exception\ResponseException;
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
     * A Guzzle middleware for the shared HTTP client. A feature timeout at or below the budget is left alone, so a real
     * timeout keeps its own reason instead of turning into busy.
     */
    public function middleware(): Closure
    {
        return fn (callable $handler): Closure => function (RequestInterface $request, array $options) use ($handler) {
            $afterMs = $this->afterMs;
            $timeoutMs = (float) ($options['timeout'] ?? 0) * 1000;

            if (! $this->appliesTo($request) || ($timeoutMs > 0 && $timeoutMs <= $afterMs)) {
                return $handler($request, $options);
            }

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

    /**
     * With the budget as the request's timeout, a timeout means the budget ran out. The cURL handler says so ("cURL
     * error 28"); Guzzle's stream handler, which streamed chat requests use, does not: a silent server makes fopen()
     * fail with a bare "Failed to open stream: HTTP request failed!", raised as a GuzzleHttp RequestException (not a
     * ResponseException, which carries a response). So any failure without a response that comes once the budget has
     * (almost) run out counts too; a refused connection or a DNS error fails long before that.
     */
    private function exceeded(Throwable $failure, int $afterMs, int $startedAt): Throwable
    {
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
        $withoutResponse = ! $failure instanceof ResponseException;
        $timedOut = FallbackPolicy::timedOut($failure) || ($withoutResponse && $elapsedMs >= $afterMs * 0.9);

        return $timedOut ? new FirstTokenBudgetExceeded($afterMs, $failure) : $failure;
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
