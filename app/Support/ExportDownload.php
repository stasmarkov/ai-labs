<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\ExportDelivery;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Offload answers at once with the internal-redirect header and an empty body; stream writes the file from PHP.
 */
final readonly class ExportDownload implements Responsable
{
    public function __construct(
        private ExportDelivery $delivery,
        private ExportDownloadEntry $entry,
        private ExportStreamer $streamer,
    ) {}

    public function toResponse($request): Response
    {
        $delivery = $this->delivery;
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$delivery->id.'.csv"',
            'Content-Length' => (string) $delivery->bytes,
            'X-Export-Mode' => $delivery->mode->value,
        ];

        if ($delivery->headerName !== null && $delivery->headerValue !== null) {
            return response('', 200, $headers + [$delivery->headerName => $delivery->headerValue]);
        }

        // A HEAD request gets the headers only: PHP neither reads the file nor logs bytes for it.
        if ($request->isMethod('HEAD')) {
            return response('', 200, $headers);
        }

        return response()->stream(
            fn () => $this->streamer->send($delivery->path, $this->entry),
            200,
            $headers + ['X-Accel-Buffering' => 'no', 'Cache-Control' => 'no-cache, no-transform'],
        );
    }
}
