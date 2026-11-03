<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ServerTiming;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AddServerTimingHeader
{
    public function __construct(private readonly ServerTiming $timing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->timing->reset();

        $response = $next($request);
        $header = $this->timing->header();

        if ($header !== null) {
            $response->headers->set('Server-Timing', $header);
        }

        return $response;
    }
}
