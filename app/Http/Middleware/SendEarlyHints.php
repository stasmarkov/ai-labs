<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\EarlyHintSender;
use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SendEarlyHints
{
    public function __construct(private readonly EarlyHintSender $sender) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->sendHints();

        return $next($request);
    }

    private function sendHints(): void
    {
        try {
            if (! config('performance.early_hints') || ! $this->sender->isAvailable()) {
                return;
            }

            $links = $this->links();

            if ($links !== []) {
                $this->sender->send($links);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Resolved by Vite itself on a clone, so the links are exactly what the final
     * AddLinkHeadersForPreloadedAssets header will carry, and the shared instance is untouched.
     *
     * @return list<string>
     */
    private function links(): array
    {
        $directory = (string) config('performance.early_hint_build_directory');
        $vite = clone app(Vite::class);

        if ($vite->isRunningHot() || ! is_file(public_path($directory.'/manifest.json'))) {
            return [];
        }

        $vite((array) config('performance.early_hint_entries'), $directory);

        return collect($vite->preloadedAssets())
            ->map(fn (array $attributes, string $url): string => "<{$url}>; ".implode('; ', $attributes))
            ->values()
            ->all();
    }
}
