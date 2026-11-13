<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\LlmBenchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Sends one prompt of about 1.5 times num_ctx that opens with a marker code, at the configured num_ctx and at twice it,
 * and records how much of the prompt Ollama evaluated and whether the answer still names the code. Which end is cut
 * is not assumed; the result is reported as observed.
 */
final readonly class LlmContextBench
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    private const FILLER = 'Well kept family estate, one owner since new, full service history, two keys, new tyres last spring, no accidents, non-smoker, available for a viewing on weekdays after five. ';

    private const QUESTION = "\n\nWhat was the code in the first sentence? Answer with the code only.";

    public function __construct(private LlmSpeedBench $speed) {}

    /**
     * A code of 6 characters from A-Z and 0-9, the same for the same date.
     */
    public function code(string $date): string
    {
        return (new Randomizer(new Mt19937(crc32($date))))->getBytesFromString(self::ALPHABET, 6);
    }

    /**
     * @return array{num_ctx: int, effective_context_length: int|null, estimated_prompt_tokens: int, over_window: bool|null, configured: array<string, mixed>, doubled: array<string, mixed>}
     */
    public function measure(string $model, string $date): array
    {
        $numCtx = (int) config('ai.providers.ollama.request_options.num_ctx', 8192);
        $code = $this->code($date);
        $prompt = $this->prompt($code, $numCtx);

        $this->speed->unloadAll();
        $configured = $this->ask($model, $prompt, $numCtx, $code);
        $configured['effective_context_length'] = $this->effectiveWindow($model);
        $doubled = $this->ask($model, $prompt, 2 * $numCtx, $code);
        $doubled['effective_context_length'] = $this->effectiveWindow($model);
        $this->speed->unload($model);

        $window = $configured['effective_context_length'] ?? $numCtx;
        $counts = [$configured['prompt_eval_count'], $doubled['prompt_eval_count']];
        $overWindow = $doubled['prompt_eval_count'] === null ? null : $doubled['prompt_eval_count'] > $window;
        $equal = $counts[0] !== null && $counts[0] === $counts[1];

        $configured['num_ctx_applied'] = $configured['effective_context_length'] === null ? null : $configured['effective_context_length'] === $numCtx;
        $configured['context_shift_suspected'] = $equal && $overWindow === true;
        $configured['truncated'] = match (true) {
            $counts[0] === null || $counts[1] === null => null,
            $equal && $overWindow === true => null,
            default => $counts[0] < $counts[1],
        };
        // Some runners keep the model loaded at its first window, so the doubled request may not have been applied: its own
        // window is read the same way, and a count above it is a shift, not a fit.
        $doubledWindow = $doubled['effective_context_length'] ?? 2 * $numCtx;
        $doubled['num_ctx_applied'] = $doubled['effective_context_length'] === null ? null : $doubled['effective_context_length'] === 2 * $numCtx;
        $doubled['context_shift_suspected'] = $doubled['prompt_eval_count'] !== null && $doubled['prompt_eval_count'] > $doubledWindow;
        $doubled['truncated'] = match (true) {
            $doubled['prompt_eval_count'] === null => null,
            $doubled['context_shift_suspected'] => null,
            default => $doubled['prompt_eval_count'] >= 2 * $numCtx,
        };

        return [
            'num_ctx' => $numCtx,
            'effective_context_length' => $configured['effective_context_length'],
            'estimated_prompt_tokens' => (int) ceil(mb_strlen($prompt) / 4),
            'over_window' => $overWindow,
            'configured' => $configured,
            'doubled' => $doubled,
        ];
    }

    private function effectiveWindow(string $model): ?int
    {
        $length = $this->speed->loaded($model)['context_length'] ?? null;

        return is_numeric($length) ? (int) $length : null;
    }

    private function prompt(string $code, int $numCtx): string
    {
        $head = "The code is {$code}. ";
        $length = (int) (1.5 * $numCtx * 4) - mb_strlen(self::QUESTION);
        $body = $head.str_repeat(self::FILLER, (int) ceil($length / mb_strlen(self::FILLER)));
        $cut = mb_substr($body, 0, $length);

        return mb_substr($cut, 0, (int) mb_strrpos($cut, ' ')).self::QUESTION;
    }

    /**
     * @return array<string, mixed>
     */
    private function ask(string $model, string $prompt, int $numCtx, string $code): array
    {
        $row = ['num_ctx' => $numCtx, 'prompt_eval_count' => null, 'truncated' => null, 'recalled' => null, 'error' => null];

        try {
            $response = $this->client()->post(rtrim((string) config('ai.providers.ollama.url'), '/').'/api/chat', [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'stream' => false,
                'think' => false,
                'keep_alive' => LlmSpeedBench::KEEP_ALIVE,
                'options' => ['temperature' => 0, 'num_predict' => 32, 'num_ctx' => $numCtx],
            ])->throw();
        } catch (ConnectionException) {
            return [...$row, 'error' => LlmBenchException::unreachable()->getMessage()];
        } catch (RequestException $e) {
            return [...$row, 'error' => LlmBenchException::requestFailed('/api/chat', $e->response->status())->getMessage()];
        }

        $count = $response->json('prompt_eval_count');

        return [
            ...$row,
            'prompt_eval_count' => is_numeric($count) ? (int) $count : null,
            'recalled' => stripos((string) $response->json('message.content'), $code) !== false,
        ];
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->timeout(600)->connectTimeout(5);
    }
}
