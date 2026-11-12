<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Refuses a browser request that another site started. "api/*" answers CORS preflights with a wildcard origin, so a
 * JSON body alone does not keep another site from calling a route that runs a model; the browser's Sec-Fetch-Site
 * header does.
 */
final class RefuseCrossSiteRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        self::assertSameSite($request);

        return $next($request);
    }

    /**
     * @throws AccessDeniedHttpException when the browser marks the request as cross-site
     */
    public static function assertSameSite(Request $request): void
    {
        if ($request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            throw new AccessDeniedHttpException('This endpoint only answers requests from this site.');
        }
    }
}
