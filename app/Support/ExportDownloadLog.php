<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\ShutdownScheduler;
use App\DTOs\ExportDelivery;
use Illuminate\Support\Str;
use Throwable;

/**
 * One JSON line per download in storage/logs/exports.jsonl, written when the PHP request ends.
 *
 * cpu_ms is user plus system CPU of the whole request: the getrusage() reading taken in public/index.php
 * (LARAVEL_START_RUSAGE) is the baseline, so framework boot is included. That only holds where one PHP
 * process serves one request at a time (FPM, Apache, OpenLiteSpeed lsphp). FrankenPHP runs requests as
 * threads of one process and PHP has no per-thread getrusage, so there cpu_ms is null and cpu_scope is
 * "process"; the bench reports that as "n/a (shared process)".
 */
final readonly class ExportDownloadLog
{
    public const CPU_SCOPE_REQUEST = 'request';

    public const CPU_SCOPE_PROCESS = 'process';

    private const BENCH_RUN_PATTERN = '/^[A-Za-z0-9._-]+$/';

    private const BENCH_RUN_MAX = 80;

    public function __construct(private ShutdownScheduler $scheduler) {}

    public function begin(ExportDelivery $delivery, string $server, ?string $benchRun): ExportDownloadEntry
    {
        $entry = new ExportDownloadEntry(
            invocationId: (string) Str::uuid7(),
            exportId: $delivery->id,
            server: $server,
            mode: $delivery->mode->value,
            benchRun: self::cleanBenchRun($benchRun),
            startedAt: $this->requestStart(),
            cpuStart: $this->sharesProcess($server) ? null : $this->cpuBaseline(),
        );

        $this->scheduler->defer(fn () => $this->write($entry));

        return $entry;
    }

    /**
     * Keeps a tag a client could not use to forge another run: only [A-Za-z0-9._-], cut to 80 characters, else null.
     */
    public static function cleanBenchRun(?string $benchRun): ?string
    {
        if ($benchRun === null || preg_match(self::BENCH_RUN_PATTERN, $benchRun) !== 1) {
            return null;
        }

        return substr($benchRun, 0, self::BENCH_RUN_MAX);
    }

    /**
     * Runs in a shutdown function, where an exception would be lost, so it reports instead of throwing.
     */
    public function write(ExportDownloadEntry $entry): void
    {
        try {
            $this->append($entry);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function append(ExportDownloadEntry $entry): void
    {
        $line = [
            'invocation_id' => $entry->invocationId,
            'export_id' => $entry->exportId,
            'server' => $entry->server,
            'mode' => $entry->mode,
            'bench_run' => $entry->benchRun,
            'php_held_ms' => round((microtime(true) - $entry->startedAt) * 1000, 1),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'cpu_ms' => $this->cpuMilliseconds($entry),
            'cpu_scope' => $entry->cpuStart === null ? self::CPU_SCOPE_PROCESS : self::CPU_SCOPE_REQUEST,
            'bytes_sent_by_php' => $entry->bytesSentByPhp,
            'aborted' => $entry->aborted,
            'abort_reason' => $entry->abortReason,
        ];

        $path = (string) config('exports.log');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($line, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n", FILE_APPEND | LOCK_EX);
    }

    private function cpuMilliseconds(ExportDownloadEntry $entry): ?float
    {
        if ($entry->cpuStart === null) {
            return null;
        }

        [$user, $system] = self::cpuSeconds(getrusage() ?: []);

        return round(($user - $entry->cpuStart[0] + $system - $entry->cpuStart[1]) * 1000, 1);
    }

    private function sharesProcess(string $server): bool
    {
        foreach ((array) config('exports.shared_process_servers') as $needle) {
            if (stripos($server, (string) $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function requestStart(): float
    {
        if (defined('LARAVEL_START')) {
            return (float) LARAVEL_START;
        }

        return (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    }

    /**
     * The reading from public/index.php when there is one, else from now (tests, Artisan).
     *
     * @return array{float, float}
     */
    private function cpuBaseline(): array
    {
        $usage = defined('LARAVEL_START_RUSAGE') ? (array) constant('LARAVEL_START_RUSAGE') : (getrusage() ?: []);

        return self::cpuSeconds($usage);
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array{float, float}
     */
    private static function cpuSeconds(array $usage): array
    {
        return [
            (float) ($usage['ru_utime.tv_sec'] ?? 0) + (float) ($usage['ru_utime.tv_usec'] ?? 0) / 1e6,
            (float) ($usage['ru_stime.tv_sec'] ?? 0) + (float) ($usage['ru_stime.tv_usec'] ?? 0) / 1e6,
        ];
    }
}
