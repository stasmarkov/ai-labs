<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lets a decision on a pending change through only when the browser says the request comes from this site's own page.
 * A client that is not a browser, such as a model running curl, does not send `Sec-Fetch-Site: same-origin`, so it is
 * refused; the header is a forbidden header name, so page script in another site cannot set it. When an Origin header
 * is sent, it must be the origin of APP_URL.
 */
final class RequireSameOriginDecision
{
    private const MESSAGE = 'A change is confirmed or rejected only from its page in the browser.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->get('Sec-Fetch-Site') !== 'same-origin') {
            throw new AccessDeniedHttpException(self::MESSAGE);
        }

        $origin = $request->headers->get('Origin');

        if ($origin !== null && strtolower(rtrim($origin, '/')) !== self::appOrigin()) {
            throw new AccessDeniedHttpException(self::MESSAGE);
        }

        return $next($request);
    }

    private static function appOrigin(): string
    {
        $parts = parse_url((string) config('app.url')) ?: [];

        return strtolower(($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? 'localhost').(isset($parts['port']) ? ':'.$parts['port'] : ''));
    }
}
