<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Ai\AiProviders;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 for the change pages and the confirm and reject endpoints unless the app runs locally with AI_MCP_SERVER
 * on, so outside it the routes do not exist in practice.
 */
final readonly class EnsureMcpServer
{
    public function __construct(private AiProviders $providers) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->providers->mcpServerAvailable(), 404);

        return $next($request);
    }
}
