<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Throwable;

/**
 * Whether and how one feature falls back: the smaller model on the same provider, and the first-token budget in
 * milliseconds after which the main model counts as busy (null: only errors trigger).
 */
final readonly class FallbackPolicy
{
    public function __construct(
        public ?string $model = null,
        public ?int $afterMs = null,
    ) {}

    public static function off(): self
    {
        return new self;
    }

    public function enabled(): bool
    {
        return filled($this->model);
    }

    /**
     * The trigger a failure maps to, or null when it is not one: a spent first-token budget (busy, anywhere in the
     * chain), a refused connection, a 404, a 5xx or a timeout fall back; client errors do not. First match wins.
     */
    public static function reasonFor(Throwable $failure): ?string
    {
        for ($e = $failure; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof FirstTokenBudgetExceeded) {
                return 'busy';
            }
        }

        for ($e = $failure; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof RequestException && $e->response !== null) {
                $status = $e->response->status();

                if ($status === 404) {
                    return 'model_missing';
                }

                if ($status >= 500) {
                    return 'server_error';
                }
            }

            if ($e instanceof ProviderOverloadedException || $e instanceof StreamErrorException) {
                return 'server_error';
            }

            if ($e instanceof ConnectionException || $e instanceof ProviderConnectionException) {
                return self::timedOut($failure) || self::streamOpenFailed($failure) ? 'timeout' : 'unreachable';
            }
        }

        return null;
    }

    /**
     * Guzzle's stream handler (streamed chat requests) reports a timeout while waiting for headers as a bare
     * "Error creating resource: [message] fopen(<url>): Failed to open stream: HTTP request failed!" (a GuzzleHttp
     * RequestException without a response, which Laravel wraps in a ConnectionException); a refused connection names
     * its cause ("Connection refused"), so the bare message counts as a timeout.
     */
    private static function streamOpenFailed(Throwable $failure): bool
    {
        for ($e = $failure; $e !== null; $e = $e->getPrevious()) {
            if (str_contains($e->getMessage(), 'HTTP request failed!') && ! preg_match('/refused|resolve|unreachable/i', $e->getMessage())) {
                return true;
            }
        }

        return false;
    }

    public static function timedOut(Throwable $failure): bool
    {
        for ($e = $failure; $e !== null; $e = $e->getPrevious()) {
            if (preg_match('/timed out|timeout|cURL error 28/i', $e->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }
}
