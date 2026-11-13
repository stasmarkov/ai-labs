<?php

declare(strict_types=1);

namespace App\Ai;

use App\DTOs\AdminChatCompletion;
use App\DTOs\AdminToolCall;
use App\Exceptions\AdminAssistantModelException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Ollama's OpenAI-compatible chat API over plain HTTP, with no SDK in between. One call, no retries: the loop above
 * counts calls, so a retry here would hide one. Thinking is always off here, whatever AI_THINK says: the loop shows the
 * messages and the tool calls, and a reasoning block would only cost time and tokens.
 */
final readonly class OllamaChatClient
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @param  string  $toolChoice  "auto" or "none"
     *
     * @throws AdminAssistantModelException
     */
    public function complete(string $provider, string $model, array $messages, array $tools, string $toolChoice): AdminChatCompletion
    {
        $body = [
            'model' => $model,
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => $toolChoice,
            'stream' => false,
            'temperature' => 0,
            'think' => false,
        ];

        try {
            $response = $this->request($provider)->post('/v1/chat/completions', $body);
        } catch (ConnectionException $exception) {
            throw AdminAssistantModelException::unavailable('connection failed', $exception);
        }

        if ($response->failed()) {
            $message = (string) ($response->json('error.message') ?? $response->json('error') ?? '');

            if ($response->status() === 400 && str_contains(mb_strtolower($message), 'does not support tools')) {
                throw AdminAssistantModelException::unsupported($model);
            }

            throw AdminAssistantModelException::unavailable('status '.$response->status(), new RequestException($response));
        }

        $choice = $response->json('choices.0.message');

        if (! is_array($choice)) {
            throw AdminAssistantModelException::unavailable('the answer has no choices');
        }

        return new AdminChatCompletion(
            content: is_string($choice['content'] ?? null) ? $choice['content'] : '',
            toolCalls: $this->toolCalls($choice['tool_calls'] ?? []),
            tokensIn: $this->count($response->json('usage.prompt_tokens')),
            tokensOut: $this->count($response->json('usage.completion_tokens')),
        );
    }

    private function request(string $provider): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config("ai.providers.{$provider}.url"), '/'))
            ->timeout((int) config("ai.providers.{$provider}.timeout", 120))
            ->acceptJson()
            ->asJson();

        $key = (string) config("ai.providers.{$provider}.key", '');

        return $key === '' ? $request : $request->withToken($key);
    }

    /**
     * @return list<AdminToolCall>
     */
    private function toolCalls(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $calls = [];

        foreach ($raw as $call) {
            $function = is_array($call) ? ($call['function'] ?? null) : null;

            if (! is_array($function) || ! is_string($function['name'] ?? null)) {
                continue;
            }

            $arguments = $function['arguments'] ?? '';

            $calls[] = new AdminToolCall(
                id: is_string($call['id'] ?? null) && $call['id'] !== '' ? $call['id'] : 'call_'.Str::random(8),
                name: $function['name'],
                arguments: is_string($arguments) ? $arguments : (string) json_encode($arguments),
            );
        }

        return $calls;
    }

    private function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
