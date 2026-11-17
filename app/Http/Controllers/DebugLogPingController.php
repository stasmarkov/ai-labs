<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * /api/ping plus one info entry on the default log channel. The log-channel overhead run loads it with LOG_STACK=single
 * and with LOG_STACK=single,frankenphp: both write the entry, so the difference is the frankenphp channel alone.
 */
final class DebugLogPingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        Log::info('ping');

        return ApiResponse::success(['ping' => 'pong'], 'pong');
    }
}
