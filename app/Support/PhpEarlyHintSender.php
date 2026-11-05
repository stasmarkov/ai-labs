<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\EarlyHintSender;

/**
 * headers_send exists only on SAPIs that can write an informational response (FrankenPHP). In worker mode
 * (FrankenPHP worker, Octane) the hint is skipped, because the response is built by a long-lived script.
 * The Link headers set for the hint are removed afterwards so the final response carries only its own.
 * Worker mode is detected from the process environment as well as $_SERVER, because inside a worker loop
 * the per-request $_SERVER is rebuilt and may not carry the boot-time marker. The constructor arguments are
 * test seams; null reads the real PHP runtime. $server and $environment replace $_SERVER and the process
 * environment (getenv and $_ENV).
 *
 * @param  array<string, mixed>|null  $server
 * @param  array<string, mixed>|null  $environment
 */
final class PhpEarlyHintSender implements EarlyHintSender
{
    public function __construct(
        private readonly ?bool $hasHeadersSend = null,
        private readonly ?bool $headersAlreadySent = null,
        private readonly ?array $server = null,
        private readonly ?array $environment = null,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->inWorkerMode()) {
            return false;
        }

        if (! ($this->hasHeadersSend ?? function_exists('headers_send'))) {
            return false;
        }

        return ! ($this->headersAlreadySent ?? headers_sent());
    }

    private function inWorkerMode(): bool
    {
        $server = $this->server ?? $_SERVER;
        $environment = $this->environment ?? [...$_ENV, ...getenv()];

        return isset($server['FRANKENPHP_WORKER'])
            || isset($environment['FRANKENPHP_WORKER'])
            || isset($environment['LARAVEL_OCTANE']);
    }

    public function send(array $links): void
    {
        foreach ($links as $link) {
            header('Link: '.$link, false);
        }

        headers_send(103);
        header_remove('Link');
    }
}
