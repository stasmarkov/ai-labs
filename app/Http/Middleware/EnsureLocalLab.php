<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Ai\AiProviders;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\JsonErrorRenderer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 outside the local environment. The prompt playground is a free-form model call with a stored history, so
 * a public deploy must not offer it: its page and every endpoint sit behind this gate. A JSON request gets the code
 * `local_only`, so the page can tell this refusal from a missing item.
 */
final class EnsureLocalLab
{
    public const CODE = 'local_only';

    public static function available(): bool
    {
        return app(AiProviders::class)->playgroundAvailable();
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (self::available()) {
            return $next($request);
        }

        if (JsonErrorRenderer::wantsJson($request)) {
            return ApiResponse::error(404, 'Not found', extra: ['code' => self::CODE]);
        }

        abort(404);
    }
}
