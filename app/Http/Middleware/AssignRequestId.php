<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Logging\RequestContextProcessor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public const string HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9-]{8,64}$/D', $incoming) === 1
            ? $incoming
            : (string) Str::ulid();

        Context::addHidden(RequestContextProcessor::REQUEST_ID_KEY, $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
