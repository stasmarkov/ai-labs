<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Ai\AiProviders;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 for the admin assistant routes unless the app runs locally with AI_ADMIN_ASSISTANT on, the same gate the
 * settings row uses, so outside it the routes do not exist in practice.
 */
final readonly class EnsureAdminAssistant
{
    public function __construct(private AiProviders $providers) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->providers->adminAssistantAvailable(), 404);

        return $next($request);
    }
}
