<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class DebugWorkerController extends Controller
{
    private static int $workerHits = 0;

    private static ?string $workerId = null;

    private static ?string $firstSeenAt = null;

    public function __invoke(): JsonResponse
    {
        abort_unless(config('app.debug_routes') === true, 404);

        self::$workerHits++;
        self::$workerId ??= bin2hex(random_bytes(8));
        self::$firstSeenAt ??= now()->toIso8601String();

        return ApiResponse::success([
            'pid' => getmypid(),
            'worker_id' => self::$workerId,
            'app_id' => spl_object_id(app()),
            'memory_bytes' => memory_get_usage(),
            'peak_memory_bytes' => memory_get_peak_usage(),
            'worker_hits' => self::$workerHits,
            'first_seen_at' => self::$firstSeenAt,
            'octane' => getenv('LARAVEL_OCTANE') !== false,
        ], 'worker');
    }
}
