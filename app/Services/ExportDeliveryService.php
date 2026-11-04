<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ExportDelivery;
use App\Enums\ExportDeliveryMode;
use App\Enums\ExportMode;
use App\Enums\OffloadHeader;
use App\Exceptions\ExportNotFoundException;
use App\Support\ExportDownload;
use App\Support\ExportDownloadLog;
use App\Support\ExportStreamer;

final readonly class ExportDeliveryService
{
    public function __construct(
        private ExportFileService $files,
        private ExportDownloadLog $log,
        private ExportStreamer $streamer,
    ) {}

    /**
     * @param  bool  $sendfileIntercept  whether the server config in front of PHP says it serves X-Sendfile itself.
     *
     * @throws ExportNotFoundException when the id has no record or its file is missing.
     */
    public function deliver(string $id, ExportMode $mode, string $serverSoftware, ?string $benchRun, bool $sendfileIntercept = false): ExportDownload
    {
        $delivery = $this->plan($id, $mode, $serverSoftware, $sendfileIntercept);

        return new ExportDownload($delivery, $this->log->begin($delivery, $serverSoftware, $benchRun), $this->streamer);
    }

    /**
     * @throws ExportNotFoundException when the id has no record or its file is missing.
     */
    public function plan(string $id, ExportMode $mode, string $serverSoftware, bool $sendfileIntercept = false): ExportDelivery
    {
        $path = $this->files->locate($id);
        $bytes = (int) filesize($path);

        if ($mode === ExportMode::Stream) {
            return new ExportDelivery($id, $path, $bytes, ExportDeliveryMode::Stream, null, null);
        }

        $header = $this->headerFor($serverSoftware);

        if ($header === OffloadHeader::None
            || ! in_array($header->value, $this->enabledHeaders(), true)
            || ($header === OffloadHeader::XSendfile && ! $sendfileIntercept && $this->needsIntercept($serverSoftware))) {
            return new ExportDelivery($id, $path, $bytes, ExportDeliveryMode::StreamFallback, null, null);
        }

        $value = $header->usesUri() ? rtrim((string) config('exports.uri_prefix'), '/')."/{$id}.csv" : $path;

        return new ExportDelivery($id, $path, $bytes, ExportDeliveryMode::Offload, $header->headerName(), $value);
    }

    private function headerFor(string $serverSoftware): OffloadHeader
    {
        $forced = config('exports.offload_header');

        if (is_string($forced) && $forced !== '') {
            return OffloadHeader::tryFrom($forced) ?? OffloadHeader::None;
        }

        foreach ((array) config('exports.offload') as $needle => $header) {
            if (stripos($serverSoftware, (string) $needle) !== false) {
                return OffloadHeader::tryFrom((string) $header) ?? OffloadHeader::None;
            }
        }

        return OffloadHeader::None;
    }

    /**
     * FrankenPHP worker mode and Octane report the same server software as the classic mode but nothing intercepts
     * X-Sendfile there, so these servers only offload when the Caddyfile that does intercept it has said so.
     */
    private function needsIntercept(string $serverSoftware): bool
    {
        foreach ((array) config('exports.sendfile_needs_intercept') as $needle) {
            if (stripos($serverSoftware, (string) $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function enabledHeaders(): array
    {
        return array_values((array) config('exports.offload_enabled'));
    }
}
