<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class OllamaVisionModels
{
    /**
     * Whether the model is installed on the Ollama server and reports the "vision" capability.
     */
    public function has(string $model): bool
    {
        $url = rtrim((string) config('ai.providers.ollama.url'), '/');

        try {
            $installed = collect((array) Http::timeout(5)->acceptJson()->get($url.'/api/tags')->throw()->json('models', []))
                ->contains(fn (mixed $entry): bool => is_array($entry) && in_array($model, [$entry['name'] ?? null, $entry['model'] ?? null], true));

            if (! $installed) {
                return false;
            }

            $capabilities = Http::timeout(5)->acceptJson()->post($url.'/api/show', ['model' => $model])->throw()->json('capabilities', []);
        } catch (Throwable) {
            return false;
        }

        return in_array('vision', (array) $capabilities, true);
    }
}
