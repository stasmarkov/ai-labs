<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides the /debug/* routes completely while DEBUG_ROUTES is off: every method gets the same 404 as an
 * unknown path (registering them for GET only would make other methods answer 405 with an Allow header,
 * which reveals that the routes exist). When enabled, only GET and HEAD are allowed.
 */
final class EnsureDebugRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('app.debug_routes') === true, 404);
        abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 405);

        return $next($request);
    }
}
