<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class OllamaServerInfo
{
    /**
     * The server's version from /api/version, or null when the provider is not Ollama or the server does not answer.
     */
    public function version(string $provider): ?string
    {
        if ($provider !== 'ollama') {
            return null;
        }

        try {
            $version = Http::timeout(5)
                ->acceptJson()
                ->get(rtrim((string) config('ai.providers.ollama.url'), '/').'/api/version')
                ->throw()
                ->json('version');
        } catch (Throwable) {
            return null;
        }

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Whether /api/version answers within one second; the answer is cached for 10 s.
     */
    public function reachable(): bool
    {
        return Cache::remember('ai-lab:ollama-reachable', 10, function (): bool {
            try {
                Http::timeout(1)
                    ->connectTimeout(1)
                    ->acceptJson()
                    ->get(rtrim((string) config('ai.providers.ollama.url'), '/').'/api/version')
                    ->throw();
            } catch (Throwable) {
                return false;
            }

            return true;
        });
    }

    /**
     * OLLAMA_NUM_PARALLEL as set for this app, or null when unset or when the provider is not Ollama.
     */
    public function numParallel(string $provider): ?int
    {
        $value = config('ai.providers.ollama.num_parallel');

        return $provider === 'ollama' && is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
