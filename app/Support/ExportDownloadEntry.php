<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What one download measures about itself, filled in while PHP works and written once at shutdown.
 */
final class ExportDownloadEntry
{
    public const ABORT_CLIENT = 'client_disconnect';

    public const ABORT_MAX_DURATION = 'max_duration';

    public int $bytesSentByPhp = 0;

    public bool $aborted = false;

    public ?string $abortReason = null;

    /**
     * @param  array{float, float}|null  $cpuStart  user and system CPU seconds when the request began, or null when the
     *                                              PHP process is shared between requests and no per-request figure exists.
     */
    public function __construct(
        public readonly string $invocationId,
        public readonly string $exportId,
        public readonly string $server,
        public readonly string $mode,
        public readonly ?string $benchRun,
        public readonly float $startedAt,
        public readonly ?array $cpuStart,
    ) {}

    public function abort(string $reason): void
    {
        $this->aborted = true;
        $this->abortReason = $reason;
    }
}
