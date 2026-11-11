<?php

declare(strict_types=1);

namespace App\Ai;

use App\DTOs\ModelOption;
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
     * The models /api/tags lists, each classed from /api/show capabilities as text or embedding (models with neither are left out); cached for 10 s. Empty when the server does not answer.
     *
     * @return list<ModelOption>
     */
    public function models(): array
    {
        $names = Cache::remember('ai-lab:ollama-models', 10, function (): array {
            try {
                $tags = Http::timeout(2)
                    ->connectTimeout(1)
                    ->acceptJson()
                    ->get(rtrim((string) config('ai.providers.ollama.url'), '/').'/api/tags')
                    ->throw()
                    ->json('models');
            } catch (Throwable) {
                return [];
            }

            $rows = [];

            foreach (is_array($tags) ? $tags : [] as $tag) {
                if (is_array($tag) && is_string($tag['name'] ?? null) && $tag['name'] !== '') {
                    $rows[] = ['name' => $tag['name'], 'details' => is_array($tag['details'] ?? null) ? $tag['details'] : []];
                }
            }

            return $rows;
        });

        // The classified list is cached as plain rows for the same 10 s, so a page that asks for it once per feature
        // row costs one cache read per call instead of one per model, and no /api/show call at all within the window.
        /** @var list<array{name: string, kind: string, vision: bool}> $rows */
        $rows = Cache::remember('ai-lab:ollama-model-options:'.md5(json_encode($names) ?: ''), 10, function () use ($names): array {
            $rows = [];

            foreach ($names as $row) {
                $option = $this->describe($row['name'], $row['details']);

                if ($option !== null) {
                    $rows[] = ['name' => self::canonical($option->name), 'kind' => $option->kind, 'vision' => $option->vision];
                }
            }

            return $rows;
        });

        return array_map(fn (array $row): ModelOption => new ModelOption($row['name'], $row['kind'], $row['vision']), $rows);
    }

    /**
     * Ollama lists every model with a tag ("bge-m3:latest") while configuration names it without one ("bge-m3"); both
     * mean the same model, so the default tag is dropped wherever names are compared or stored.
     */
    public static function canonical(string $name): string
    {
        return str_ends_with($name, ':latest') ? substr($name, 0, -strlen(':latest')) : $name;
    }

    /**
     * The model ids configured as selectable for a provider that has no model list endpoint.
     *
     * @return list<string>
     */
    public function available(string $provider, string $kind = 'text'): array
    {
        $available = config("ai.providers.{$provider}.models.{$kind}.available");

        return is_array($available) ? array_values(array_filter($available, is_string(...))) : [];
    }

    /**
     * The model's kind from /api/show capabilities (cached 10 minutes per name): embedding, completion (text), or
     * neither, which is not offered. When /api/show does not answer, the name and family guess stands in, uncached.
     *
     * @param  array<array-key, mixed>  $details
     */
    private function describe(string $name, array $details): ?ModelOption
    {
        $key = 'ai-lab:ollama-show:'.$name;
        $capabilities = Cache::get($key);

        if (! is_array($capabilities)) {
            try {
                $capabilities = Http::timeout(3)
                    ->connectTimeout(1)
                    ->acceptJson()
                    ->post(rtrim((string) config('ai.providers.ollama.url'), '/').'/api/show', ['model' => $name])
                    ->throw()
                    ->json('capabilities');
            } catch (Throwable) {
                $capabilities = null;
            }

            if (! is_array($capabilities)) {
                // Not cached as capabilities: the guess stands in, and the outer 10 s cache keeps the failed lookup
                // from being repeated on every call.
                return new ModelOption($name, $this->guessKind($name, $details));
            }

            Cache::put($key, $capabilities, 600);
        }

        $vision = in_array('vision', $capabilities, true);

        if (in_array('embedding', $capabilities, true)) {
            return new ModelOption($name, ModelOption::EMBEDDING, $vision);
        }

        return in_array('completion', $capabilities, true) ? new ModelOption($name, ModelOption::TEXT, $vision) : null;
    }

    /**
     * @param  array<array-key, mixed>  $details
     */
    private function guessKind(string $name, array $details): string
    {
        $families = array_filter([$details['family'] ?? null, ...(is_array($details['families'] ?? null) ? $details['families'] : [])], is_string(...));

        if (preg_match('/embed|bge|minilm|e5|gte/i', $name) === 1) {
            return ModelOption::EMBEDDING;
        }

        foreach ($families as $family) {
            if (str_contains($family, 'bert')) {
                return ModelOption::EMBEDDING;
            }
        }

        return ModelOption::TEXT;
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
