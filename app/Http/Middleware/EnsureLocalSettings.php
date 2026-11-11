<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Ai\AiProviders;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 for the settings routes unless the app runs locally with AI_SETTINGS_UI on, the same gate the resolver uses.
 */
final readonly class EnsureLocalSettings
{
    public function __construct(private AiProviders $providers) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->providers->settingsAvailable(), 404);

        return $next($request);
    }
}
