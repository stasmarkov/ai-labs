<?php

declare(strict_types=1);

namespace App\Support;

use App\Ai\AbortWatcher;

/**
 * Writes a file to the client in 64 KiB chunks, flushing after each, and stops reading once the client is gone.
 *
 * Stream mode holds a PHP worker for as long as the client takes to read, on purpose (that is what the bench
 * measures), and ignore_user_abort plus no time limit would let a client that reads a few bytes a second hold
 * it for hours. So streaming also stops after exports.stream_max_seconds (default 300) of wall clock and is
 * logged as aborted with the reason "max_duration"; the client gets a short download.
 */
final readonly class ExportStreamer
{
    public const CHUNK_BYTES = 65536;

    public function __construct(private AbortWatcher $watcher) {}

    public function send(string $path, ExportDownloadEntry $entry): void
    {
        // Without this PHP is terminated at the first write after a disconnect and cannot log the abort.
        ignore_user_abort(true);
        set_time_limit(0);

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        $deadline = hrtime(true) + (int) ((float) config('exports.stream_max_seconds') * 1e9);

        while (! feof($handle)) {
            $chunk = fread($handle, self::CHUNK_BYTES);

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;

            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();
            $entry->bytesSentByPhp += strlen($chunk);

            if ($this->watcher->aborted()) {
                $entry->abort(ExportDownloadEntry::ABORT_CLIENT);

                break;
            }

            if (hrtime(true) >= $deadline && ! feof($handle)) {
                $entry->abort(ExportDownloadEntry::ABORT_MAX_DURATION);

                break;
            }
        }

        fclose($handle);
    }
}
