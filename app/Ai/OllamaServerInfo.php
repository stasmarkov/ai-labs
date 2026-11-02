<?php

declare(strict_types=1);

namespace App\Ai;

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
     * OLLAMA_NUM_PARALLEL as set for this app, or null when unset or when the provider is not Ollama.
     */
    public function numParallel(string $provider): ?int
    {
        $value = config('ai.providers.ollama.num_parallel');

        return $provider === 'ollama' && is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
