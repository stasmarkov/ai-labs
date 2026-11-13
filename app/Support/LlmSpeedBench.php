<?php

declare(strict_types=1);

namespace App\Support;

use App\Ai\CarDescriptionAgent;
use App\DTOs\ListingDetail;
use App\DTOs\LlmSpeedSample;
use App\Exceptions\LlmBenchException;
use App\Services\ListingDetailService;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Measures one Ollama model with the describe feature's own prompt: a cold load after an unload, then warm streamed runs.
 * Time to first token is measured here, at the first non-empty content chunk; the token rates come from the counters
 * and durations Ollama reports in its last chunk.
 */
final readonly class LlmSpeedBench
{
    public const LISTING_ID = 1;

    /**
     * The bench keeps its own models loaded between requests whatever OLLAMA_KEEP_ALIVE says, so a warm run is warm and
     * /api/ps still lists the model afterwards. The cold run is made cold by the explicit unload, not by this value.
     */
    public const KEEP_ALIVE = '5m';

    private Closure $now;

    /**
     * @param  (Closure(): float)|null  $now  Milliseconds from a monotonic clock; replaced in tests.
     */
    public function __construct(private ListingDetailService $listings, ?Closure $now = null)
    {
        $this->now = $now ?? static fn (): float => hrtime(true) / 1_000_000;
    }

    public function version(): ?string
    {
        try {
            $version = $this->client()->get($this->url('/api/version'))->throw()->json('version');
        } catch (ConnectionException|RequestException) {
            throw LlmBenchException::unreachable();
        }

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * @param  positive-int  $runs
     * @return array<string, mixed>
     */
    public function measure(string $model, int $runs): array
    {
        $messages = $this->messages();
        $this->unloadAll();

        $cold = $this->stream($model, $this->marked($messages, 0));
        $warm = [];

        for ($run = 1; $run <= $runs; $run++) {
            $warm[] = $this->stream($model, $this->marked($messages, $run));
        }

        $loaded = $this->loaded($model);
        $ok = array_values(array_filter($warm, static fn (LlmSpeedSample $s): bool => ! $s->failed));
        $errors = array_values(array_unique(array_filter(array_map(static fn (LlmSpeedSample $s): ?string => $s->error, [$cold, ...$warm]))));

        return [
            'runs' => $runs,
            'failed_runs' => count($warm) - count($ok),
            'cold_failed' => $cold->failed,
            'errors' => $errors,
            'num_ctx' => config('ai.providers.ollama.request_options.num_ctx'),
            'keep_alive' => self::KEEP_ALIVE,
            'load_ms' => $cold->loadMs === null ? null : round($cold->loadMs, 1),
            'cold_ttft_ms' => $cold->ttftMs === null ? null : round($cold->ttftMs, 1),
            'ttft_ms' => $this->spread(array_map(fn (LlmSpeedSample $s): ?float => $s->ttftMs, $ok)),
            'prompt_eval_tokens_per_s' => $this->spread(array_map(fn (LlmSpeedSample $s): ?float => $s->promptEvalTokensPerSecond, $ok)),
            'generation_tokens_per_s' => $this->spread(array_map(fn (LlmSpeedSample $s): ?float => $s->generationTokensPerSecond, $ok)),
            'output_tokens' => $this->spread(array_map(fn (LlmSpeedSample $s): float => (float) $s->outputTokens, $ok)),
            'size' => $loaded['size'] ?? null,
            'size_vram' => $loaded['size_vram'] ?? null,
        ];
    }

    /**
     * @param  list<float|null>  $values
     * @return array{median: float, min: float, max: float}|null
     */
    public function spread(array $values): ?array
    {
        $values = array_values(array_filter($values, static fn (?float $value): bool => $value !== null));

        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

        return ['median' => round($median, 1), 'min' => round($values[0], 1), 'max' => round($values[$count - 1], 1)];
    }

    /**
     * The listing the describe prompt and the failover inputs are built from. A missing one is one specific error, here, for
     * every section of the bench.
     */
    public function listing(): ListingDetail
    {
        try {
            return $this->listings->find(self::LISTING_ID);
        } catch (ModelNotFoundException) {
            throw LlmBenchException::noListing(self::LISTING_ID);
        }
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function messages(): array
    {
        $agent = new CarDescriptionAgent;
        $detail = $this->listing();

        return [
            ['role' => 'system', 'content' => $agent->instructions()],
            ['role' => 'user', 'content' => $agent->promptFor($detail)],
        ];
    }

    /**
     * Ollama reuses the evaluated prompt prefix of the previous request, so a repeated prompt reports a prompt evaluation of
     * a few microseconds. A marker at the very start gives every request its own prefix: the model is warm, the prompt is not.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    private function marked(array $messages, int $run): array
    {
        $messages[0]['content'] = sprintf("Bench request %s-%d.\n", bin2hex(random_bytes(4)), $run).$messages[0]['content'];

        return $messages;
    }

    public function unload(string $model): void
    {
        $this->post('/api/generate', ['model' => $model, 'keep_alive' => 0]);
    }

    public function unloadAll(): void
    {
        $names = array_filter(array_map(
            static fn (mixed $entry): ?string => is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : null,
            $this->ps(),
        ));

        foreach ($names as $name) {
            $this->post('/api/generate', ['model' => $name, 'keep_alive' => 0]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loaded(string $model): ?array
    {
        foreach ($this->ps() as $entry) {
            if (is_array($entry) && in_array($model, [$entry['name'] ?? null, $entry['model'] ?? null], true)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<int, mixed>
     */
    private function ps(): array
    {
        try {
            return (array) $this->client()->get($this->url('/api/ps'))->throw()->json('models', []);
        } catch (ConnectionException) {
            throw LlmBenchException::unreachable();
        } catch (RequestException $e) {
            throw LlmBenchException::requestFailed('/api/ps', $e->response->status());
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body): void
    {
        try {
            $this->client()->post($this->url($path), $body)->throw();
        } catch (ConnectionException) {
            throw LlmBenchException::unreachable();
        } catch (RequestException $e) {
            throw LlmBenchException::requestFailed($path, $e->response->status());
        }
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function stream(string $model, array $messages): LlmSpeedSample
    {
        $options = (array) config('ai.providers.ollama.request_options', []);
        $started = ($this->now)();

        try {
            $response = $this->client()->withOptions(['stream' => true])->post($this->url('/api/chat'), [
                'model' => $model,
                'messages' => $messages,
                'stream' => true,
                'think' => false,
                'keep_alive' => self::KEEP_ALIVE,
                'options' => ['temperature' => 0, 'num_predict' => 256, 'num_ctx' => $options['num_ctx'] ?? 8192],
            ])->throw();
        } catch (ConnectionException) {
            throw LlmBenchException::unreachable();
        } catch (RequestException $e) {
            throw LlmBenchException::requestFailed('/api/chat', $e->response->status());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $state = ['first' => null, 'final' => [], 'error' => null];

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);
                $state = $this->consume($line, $state);
            }
        }

        $state = $this->consume(trim($buffer), $state);
        $final = $state['final'];

        if ($state['first'] === null) {
            $error = $state['error'] ?? 'the stream ended without a content chunk';
        }

        return new LlmSpeedSample(
            ttftMs: $state['first'] === null ? null : $state['first'] - $started,
            loadMs: $this->milliseconds($final['load_duration'] ?? null),
            promptEvalTokensPerSecond: $this->rate($final['prompt_eval_count'] ?? null, $final['prompt_eval_duration'] ?? null),
            generationTokensPerSecond: $this->rate($final['eval_count'] ?? null, $final['eval_duration'] ?? null),
            outputTokens: (int) ($final['eval_count'] ?? 0),
            failed: $state['first'] === null,
            error: $error ?? null,
        );
    }

    /**
     * @param  array{first: float|null, final: array<string, mixed>, error: string|null}  $state
     * @return array{first: float|null, final: array<string, mixed>, error: string|null}
     */
    private function consume(string $line, array $state): array
    {
        if ($line === '') {
            return $state;
        }

        $chunk = json_decode($line, true);

        if (! is_array($chunk)) {
            return $state;
        }

        if (is_string($chunk['error'] ?? null)) {
            $state['error'] = mb_substr(LlmBenchRedactor::clean($chunk['error']), 0, 300);
        }

        if ($state['first'] === null && is_string($chunk['message']['content'] ?? null) && $chunk['message']['content'] !== '') {
            $state['first'] = ($this->now)();
        }

        if (($chunk['done'] ?? false) === true) {
            $state['final'] = $chunk;
        }

        return $state;
    }

    private function milliseconds(mixed $nanoseconds): ?float
    {
        return is_numeric($nanoseconds) ? (float) $nanoseconds / 1_000_000 : null;
    }

    private function rate(mixed $count, mixed $nanoseconds): ?float
    {
        return is_numeric($count) && is_numeric($nanoseconds) && (float) $nanoseconds > 0 ? (float) $count / ((float) $nanoseconds / 1_000_000_000) : null;
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->timeout(300)->connectTimeout(5);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('ai.providers.ollama.url'), '/').$path;
    }
}
