<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Ai\AiProviders;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the "cloud-ai" limiter to a request whose resolved provider is a cloud one, so paid calls are capped per IP
 * on top of the feature's own limiter. Local providers and refused providers are not counted.
 *
 * A request counts as one hit, except a feature that makes many model calls for one request: a research run counts as
 * many hits as it may make model calls (`ai.research.max_model_calls`, 8 by default), so with 20 hits a minute per IP a
 * run needs 8 free hits to start and an IP can start two runs a minute on a cloud provider.
 */
final class LimitCloudAi
{
    /** Features that cost more than one hit per request, with the config key of the number of hits. */
    private const WEIGHTS = ['research' => 'ai.research.max_model_calls'];

    public function __construct(private readonly AiProviders $providers) {}

    /**
     * @param  string  ...$features  every AI feature the route may call (text and embeddings); one counted hit when any of them resolves to a cloud provider
     */
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $requested = $request->input('provider');
        $override = is_string($requested) ? $requested : null;
        $cloud = false;
        $weight = 1;

        foreach ($features as $feature) {
            // Only text features take the lab override; embeddings always run on their configured provider.
            // Only the lab-selectable text features take the override; the others always run on their configured provider.
            $provider = $this->providers->configured($feature, in_array($feature, AiProviders::OVERRIDABLE_FEATURES, true) ? $override : null)->provider;
            $isCloud = $this->providers->isCloud($provider) && $this->providers->isEnabled($provider);
            $cloud = $cloud || $isCloud;
            $weight = $isCloud && isset(self::WEIGHTS[$feature]) ? max($weight, (int) config(self::WEIGHTS[$feature])) : $weight;
        }

        if (! $cloud) {
            return $next($request);
        }

        // A paid call must not be triggerable from another site (an <img> or link to a GET endpoint).
        RefuseCrossSiteRequests::assertSameSite($request);

        foreach (['cloud-ai' => 'cloud-ai:', 'cloud-ai-global' => 'cloud-ai-global:'] as $name => $prefix) {
            $limit = RateLimiter::limiter($name)($request);
            $key = $prefix.$limit->key;

            if (RateLimiter::attempts($key) + $weight > $limit->maxAttempts) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
            }
        }

        foreach (['cloud-ai' => 'cloud-ai:', 'cloud-ai-global' => 'cloud-ai-global:'] as $name => $prefix) {
            $limit = RateLimiter::limiter($name)($request);
            RateLimiter::increment($prefix.$limit->key, $limit->decaySeconds, $weight);
        }

        return $next($request);
    }
}
