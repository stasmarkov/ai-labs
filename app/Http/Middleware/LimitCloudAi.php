<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Ai\AiProviders;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Applies the "cloud-ai" limiter to a request whose resolved provider is a cloud one, so paid calls are capped per IP
 * on top of the feature's own limiter. Local providers and refused providers are not counted.
 */
final class LimitCloudAi
{
    public function __construct(private readonly AiProviders $providers) {}

    /**
     * @param  string  ...$features  every AI feature the route may call (text and embeddings); one counted hit when any of them resolves to a cloud provider
     */
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $requested = $request->input('provider');
        $override = is_string($requested) ? $requested : null;
        $cloud = false;

        foreach ($features as $feature) {
            // Only text features take the lab override; embeddings always run on their configured provider.
            $provider = $this->providers->configured($feature, str_starts_with($feature, 'embeddings-') ? null : $override)->provider;
            $cloud = $cloud || ($this->providers->isCloud($provider) && $this->providers->isEnabled($provider));
        }

        if (! $cloud) {
            return $next($request);
        }

        // A paid call must not be triggerable from another site (an <img> or link to a GET endpoint).
        if ($request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            throw new AccessDeniedHttpException('Cloud providers only answer requests from this site.');
        }

        foreach (['cloud-ai' => 'cloud-ai:', 'cloud-ai-global' => 'cloud-ai-global:'] as $name => $prefix) {
            $limit = RateLimiter::limiter($name)($request);
            $key = $prefix.$limit->key;

            if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
            }
        }

        foreach (['cloud-ai' => 'cloud-ai:', 'cloud-ai-global' => 'cloud-ai-global:'] as $name => $prefix) {
            $limit = RateLimiter::limiter($name)($request);
            RateLimiter::hit($prefix.$limit->key, $limit->decaySeconds);
        }

        return $next($request);
    }
}
