<?php

declare(strict_types=1);

namespace App\Ai;

use App\Contracts\AiRunRepositoryInterface;
use App\DTOs\AiRunRecord;
use Throwable;

/**
 * Collects the timing of each streamed run and writes one ai_runs row when it ends.
 * Bound with scoped(), so under Octane its state is dropped after every request.
 */
final class AiRunRecorder
{
    private const MAX_MESSAGE_LENGTH = 1000;

    /** @var array<string, array{agent: string, provider: string, model: string, promptVersion: ?string, startedAt: int, firstTokenAt: ?int}> */
    private array $runs = [];

    public function __construct(private readonly AiRunRepositoryInterface $repository) {}

    public function started(string $invocationId, string $agent, string $provider, string $model, ?string $promptVersion): void
    {
        $this->runs[$invocationId] = [
            'agent' => $agent,
            'provider' => $provider,
            'model' => $model,
            'promptVersion' => $promptVersion,
            'startedAt' => hrtime(true),
            'firstTokenAt' => null,
        ];
    }

    public function firstToken(string $invocationId): void
    {
        if (isset($this->runs[$invocationId]) && $this->runs[$invocationId]['firstTokenAt'] === null) {
            $this->runs[$invocationId]['firstTokenAt'] = hrtime(true);
        }
    }

    public function completed(string $invocationId, int $tokensIn, int $tokensOut): void
    {
        $this->finish($invocationId, 'completed', $tokensIn, $tokensOut);
    }

    public function failed(string $invocationId, Throwable $exception): void
    {
        $this->finish(
            $invocationId,
            'failed',
            errorClass: $exception::class,
            errorMessage: mb_substr($exception->getMessage(), 0, self::MAX_MESSAGE_LENGTH),
        );
    }

    private function finish(
        string $invocationId,
        string $status,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?string $errorClass = null,
        ?string $errorMessage = null,
    ): void {
        $run = $this->runs[$invocationId] ?? null;

        if ($run === null) {
            return;
        }

        unset($this->runs[$invocationId]);

        // The SDK finishes a run inside the stream, before "[DONE]": a failed insert is reported, not sent to the client.
        rescue(fn () => $this->repository->save(new AiRunRecord(
            invocationId: $invocationId,
            agent: $run['agent'],
            provider: $run['provider'],
            model: $run['model'],
            promptVersion: $run['promptVersion'],
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            ttftMs: $run['firstTokenAt'] === null ? null : $this->millisecondsSince($run['startedAt'], $run['firstTokenAt']),
            latencyMs: $this->millisecondsSince($run['startedAt'], hrtime(true)),
            status: $status,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
        )));
    }

    private function millisecondsSince(int $start, int $end): int
    {
        return intdiv($end - $start, 1_000_000);
    }
}
