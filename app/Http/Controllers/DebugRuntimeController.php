<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DebugRuntimeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(config('app.debug_routes') === true, 404);

        $opcacheEnabled = function_exists('opcache_get_status')
            && (opcache_get_status(false)['opcache_enabled'] ?? false) === true;

        return ApiResponse::success([
            'sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'opcache_enabled' => $opcacheEnabled,
            'xdebug_loaded' => extension_loaded('xdebug'),
            'server_software' => $request->server('SERVER_SOFTWARE'),
        ], 'runtime');
    }
}
