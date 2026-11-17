<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Caddy admin API of the FrankenPHP server (config ai.server.admin_url). It listens on localhost inside the web
 * container only, so these calls work from `ddev artisan`, never from the host.
 */
final class AdminApiClient
{
    private const string PROBE_PATH = '/config/';

    private const string RESTART_PATH = '/frankenphp/workers/restart';

    private const int PROBE_TIMEOUT_SECONDS = 1;

    /**
     * True when the admin API answers a read of its config with a 2xx within one second.
     */
    public function answers(): bool
    {
        try {
            return Http::timeout(self::PROBE_TIMEOUT_SECONDS)->get($this->url(self::PROBE_PATH))->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * Restarts every FrankenPHP worker; the caller reads the status.
     *
     * @throws ConnectionException when the admin API is unreachable or the call times out
     */
    public function restartWorkers(int $timeoutSeconds): Response
    {
        return Http::timeout(max(1, $timeoutSeconds))->post($this->url(self::RESTART_PATH));
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('ai.server.admin_url'), '/');
    }

    private function url(string $path): string
    {
        return $this->baseUrl().$path;
    }
}
