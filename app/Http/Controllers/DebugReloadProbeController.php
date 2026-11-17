<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Support\ReloadProbe;
use Illuminate\Http\JsonResponse;

/**
 * Returns the marker compiled into App\Support\ReloadProbe. A worker keeps the class it loaded when it started, so the
 * answer changes only after the worker reloads the file.
 */
final class DebugReloadProbeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(['marker' => ReloadProbe::MARKER], 'reload-probe');
    }
}
