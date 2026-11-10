<?php

declare(strict_types=1);

namespace App\Guides;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

final readonly class GuideFacts
{
    public const PENDING_TEXT = 'not measured yet';

    public function __construct(private string $resultsPath) {}

    /**
     * Reads one measured number from a JSON file under the results directory.
     */
    public function number(?string $file, ?string $path, bool $pending): string
    {
        if ($pending) {
            return self::PENDING_TEXT;
        }

        $data = $this->load((string) $file);
        $value = Arr::get($data, (string) $path, null);

        if ($value === null) {
            throw new InvalidGuideException("Number path [{$path}] was not found in [{$file}].");
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    /**
     * Facts that come from the running app, never from the guide text.
     *
     * @return list<array{label: string, value: string}>
     */
    public function live(string $key): array
    {
        return match ($key) {
            'providers' => $this->providers(),
            'run-log' => $this->runLog(),
            default => [],
        };
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function providers(): array
    {
        $provider = (string) config('ai.default');
        $embeddingProvider = (string) config('ai.default_for_embeddings');

        return [
            ['label' => 'Provider', 'value' => $provider],
            ['label' => 'Model', 'value' => (string) config("ai.providers.{$provider}.models.text.default")],
            ['label' => 'Embeddings provider', 'value' => $embeddingProvider],
            ['label' => 'Embeddings model', 'value' => (string) config("ai.providers.{$embeddingProvider}.models.embeddings.default")],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function runLog(): array
    {
        return [['label' => 'ai_runs columns', 'value' => implode(', ', Schema::getColumnListing('ai_runs'))]];
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $file): array
    {
        $base = realpath($this->resultsPath);
        $real = realpath($this->resultsPath.'/'.ltrim($file, '/'));

        if ($base === false || $real === false || ! is_file($real) || ! str_starts_with($real, $base.'/')) {
            throw new InvalidGuideException("Number file [{$file}] was not found.");
        }

        $data = json_decode((string) file_get_contents($real), true);

        if (! is_array($data)) {
            throw new InvalidGuideException("Number file [{$file}] is not a JSON object.");
        }

        return $data;
    }
}
