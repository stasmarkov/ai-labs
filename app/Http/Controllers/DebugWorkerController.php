<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DebugWorkerController extends Controller
{
    private static int $workerHits = 0;

    private static ?string $workerId = null;

    private static ?string $firstSeenAt = null;

    /**
     * With ?gc=1 it runs PHP's cycle collector first, so memory_bytes is the memory the worker really keeps;
     * memory_bytes_before_gc then shows how much was garbage waiting for collection.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(config('app.debug_routes') === true, 404);

        $memoryBeforeGc = memory_get_usage();
        $collectedCycles = $request->boolean('gc') ? gc_collect_cycles() : null;

        self::$workerHits++;
        self::$workerId ??= bin2hex(random_bytes(8));
        self::$firstSeenAt ??= now()->toIso8601String();

        return ApiResponse::success([
            'pid' => getmypid(),
            'worker_id' => self::$workerId,
            'app_id' => spl_object_id(app()),
            'memory_bytes' => memory_get_usage(),
            'memory_bytes_before_gc' => $memoryBeforeGc,
            'gc_collected' => $collectedCycles,
            'peak_memory_bytes' => memory_get_peak_usage(),
            'worker_hits' => self::$workerHits,
            'first_seen_at' => self::$firstSeenAt,
            'octane' => getenv('LARAVEL_OCTANE') !== false,
        ], 'worker');
    }
}
