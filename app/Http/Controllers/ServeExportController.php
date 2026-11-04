<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ServeExportRequest;
use App\Services\ExportDeliveryService;
use Illuminate\Contracts\Support\Responsable;

final class ServeExportController extends Controller
{
    public function __invoke(string $id, ServeExportRequest $request, ExportDeliveryService $service): Responsable
    {
        return $service->deliver(
            $id,
            $request->mode(),
            (string) $request->server('SERVER_SOFTWARE', ''),
            $request->header('X-Bench-Run'),
            filter_var($request->server('EXPORTS_SENDFILE_INTERCEPT'), FILTER_VALIDATE_BOOLEAN),
        );
    }
}
