<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Sleep;

final class DebugSleepController extends Controller
{
    private const int MIN_SECONDS = 1;

    private const int MAX_SECONDS = 120;

    /**
     * The route parameter arrives as a string: casting it to int in the signature would throw a
     * TypeError (500) for values beyond PHP_INT_MAX before the debug-route guard runs.
     */
    public function __invoke(string $seconds): JsonResponse
    {
        abort_unless(config('app.debug_routes') === true, 404);

        $seconds = strlen($seconds) > 3 ? self::MAX_SECONDS + 1 : (int) $seconds;

        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            return ApiResponse::error(422, 'Seconds must be between '.self::MIN_SECONDS.' and '.self::MAX_SECONDS.'.', [
                'seconds' => ['The seconds must be between '.self::MIN_SECONDS.' and '.self::MAX_SECONDS.'.'],
            ]);
        }

        Sleep::for($seconds)->seconds();

        return ApiResponse::success(['slept_seconds' => $seconds], 'slept');
    }
}
