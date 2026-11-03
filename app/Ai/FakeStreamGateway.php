<?php

declare(strict_types=1);

namespace App\Ai;

use Generator;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use RuntimeException;

use function Laravel\Ai\ulid;

final class FakeStreamGateway implements StepTextGateway
{
    private const FILLER = [
        'in', 'excellent', 'condition', 'with', 'a', 'full', 'service', 'history', 'and', 'one', 'careful', 'owner',
        'ready', 'for', 'the', 'road', 'ahead', 'comfortable', 'capable', 'and', 'easy', 'to', 'love',
    ];

    public function __construct(
        private readonly int $tokens,
        private readonly int $delayMs,
        private readonly ?int $failAfter,
        private readonly ?string $text = null,
    ) {}

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $prompt = $this->promptFrom($messages);
        $words = $this->words($prompt);

        return new StepResponse(
            text: implode(' ', $words),
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new TextUsage($this->countWords(($instructions ?? '').' '.$prompt), count($words)),
            meta: new Meta($provider->name(), $model),
        );
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $prompt = $this->promptFrom($messages);
        $messageId = ulid();

        yield (new StreamStart(ulid(), $provider->name(), $model, time()))->withInvocationId($invocationId);
        yield (new TextStart(ulid(), $messageId, time()))->withInvocationId($invocationId);

        $text = [];

        foreach ($this->words($prompt) as $index => $word) {
            if ($this->failAfter !== null && $index >= $this->failAfter) {
                throw new RuntimeException("fake-stream failed after {$this->failAfter} tokens");
            }

            if ($index > 0 && $this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }

            $delta = $index > 0 ? ' '.$word : $word;
            $text[] = $delta;

            yield (new TextDelta(ulid(), $messageId, $delta, time()))->withInvocationId($invocationId);
        }

        yield (new TextEnd(ulid(), $messageId, time()))->withInvocationId($invocationId);

        return new StepResponse(
            text: implode('', $text),
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new TextUsage($this->countWords(($instructions ?? '').' '.$prompt), count($text)),
            meta: new Meta($provider->name(), $model),
        );
    }

    /**
     * @param  array<int, mixed>  $messages
     */
    private function promptFrom(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof UserMessage) {
                return (string) $messages[$i]->content;
            }
        }

        return '';
    }

    private function countWords(string $prompt): int
    {
        return count(preg_split('/\s+/', trim($prompt), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * The configured canned text when set, else the car named on the prompt's "Car:" line, followed by filler, as exactly the configured number of words.
     *
     * @return list<string>
     */
    private function words(string $prompt): array
    {
        if (filled($this->text)) {
            return preg_split('/\s+/', trim($this->text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $words = [];

        if (preg_match('/^Car:\s*(.+)$/mi', $prompt, $match) === 1) {
            $words = preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        for ($i = 0; count($words) < $this->tokens; $i++) {
            $words[] = self::FILLER[$i % count(self::FILLER)];
        }

        return array_slice($words, 0, $this->tokens);
    }
}
