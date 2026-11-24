<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Ai\AiProviders;
use App\DTOs\PlaygroundTarget;
use App\Services\PlaygroundCloudWeight;
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
 * run needs 8 free hits to start and an IP can start two runs a minute on a cloud provider. A playground run counts as
 * many hits as it has targets on a cloud provider, and its providers come from the request's targets.
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
            if ($feature === 'playground') {
                $count = app(PlaygroundCloudWeight::class)->cloudTargets($this->playgroundTargets($request));
                $cloud = $cloud || $count > 0;
                $weight = max($weight, $count);

                continue;
            }

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

        $this->reserve($request, $weight);

        return $next($request);
    }

    /**
     * @return list<PlaygroundTarget>
     */
    /**
     * Takes the weight from both budgets with one atomic increment each, then checks the new count, so concurrent requests
     * cannot all pass a check made before any of them counted. A refused request gives back what it took.
     *
     * @throws ThrottleRequestsException when either budget would go over its limit
     */
    private function reserve(Request $request, int $weight): void
    {
        $taken = [];

        foreach (['cloud-ai' => 'cloud-ai:', 'cloud-ai-global' => 'cloud-ai-global:'] as $name => $prefix) {
            $limit = RateLimiter::limiter($name)($request);
            $key = $prefix.$limit->key;
            $count = RateLimiter::increment($key, $limit->decaySeconds, $weight);
            $taken[$key] = $limit->decaySeconds;

            if ($count > $limit->maxAttempts) {
                foreach ($taken as $takenKey => $decaySeconds) {
                    RateLimiter::decrement($takenKey, $decaySeconds, $weight);
                }

                throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
            }
        }
    }

    private function playgroundTargets(Request $request): array
    {
        $targets = [];

        foreach (array_slice((array) $request->input('targets', []), 0, 10) as $target) {
            if (is_array($target) && is_string($target['provider'] ?? null) && is_string($target['model'] ?? null)) {
                $targets[] = new PlaygroundTarget($target['provider'], $target['model']);
            }
        }

        return $targets;
    }
}
