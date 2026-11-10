<?php

declare(strict_types=1);

namespace App\Guides;

use App\Ai\AiProviders;
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
     * A table drawn from the running app, for the guides that have one. Only the providers guide does: what each
     * provider can do, and whether its key is set, never the key.
     *
     * @return array{columns: list<string>, rows: list<array<string, string>>}|null
     */
    public function table(string $key): ?array
    {
        if ($key !== 'providers') {
            return null;
        }

        $yesNo = fn (bool $value): string => $value ? 'yes' : 'no';
        $rows = [];

        foreach (app(AiProviders::class)->capabilities() as $name => $capability) {
            $rows[] = [
                'provider' => $name,
                'where' => $capability['cloud'] ? 'cloud' : 'local',
                'text' => $yesNo($capability['text']),
                'streaming' => $yesNo($capability['streaming']),
                'structured' => $yesNo($capability['structured']),
                'embeddings' => $capability['embeddings'] === null ? 'no' : $capability['embeddings'].' dimensions',
                'key' => $capability['keyVariable'] === null
                    ? 'none needed'
                    : (app(AiProviders::class)->hasKey($name) ? 'set' : 'not set').' ('.$capability['keyVariable'].')',
            ];
        }

        return [
            'columns' => ['Provider', 'Where', 'Text', 'Streaming', 'Structured output', 'Embeddings', 'Key'],
            'rows' => $rows,
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function providers(): array
    {
        $providers = app(AiProviders::class);
        $text = $providers->configured('describe');
        $embeddings = $providers->configured('embeddings-listings');

        return [
            ['label' => 'Provider', 'value' => $text->provider],
            ['label' => 'Model', 'value' => $text->model],
            ['label' => 'Embeddings provider', 'value' => $embeddings->provider],
            ['label' => 'Embeddings model', 'value' => $embeddings->model],
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
