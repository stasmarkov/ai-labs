<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Ai\AssistantProtocol;
use App\Ai\BuyerAssistantAgent;
use App\Ai\SseEventsResponse;
use App\DTOs\DocsChatTurn;
use App\Exceptions\ProviderNotConfiguredException;
use Illuminate\Contracts\Support\Responsable;

final readonly class BuyerAssistantService
{
    public const INPUT_REFUSAL = DocsAnswerService::INPUT_REFUSAL;

    private const ORIGIN_API = 'api';

    public function __construct(private PromptInjectionGuard $guard) {}

    /**
     * Input guard, then the agent's tool loop as a stream. The guard runs before the model over the question and every
     * user turn of the history; a refused request is answered with one "refused" event and no model call, and the
     * refusal names the offending turn (its index, or "latest" for the question itself) when there is a history.
     *
     * @param  list<DocsChatTurn>  $history  earlier turns of the conversation
     * @param  string|null  $provider  an enabled text provider to run on instead of the configured one
     *
     * @throws ProviderNotConfiguredException when the configured cloud provider has no key; checked first, so nothing is guarded or recorded.
     */
    public function ask(string $question, array $history = [], ?string $provider = null): Responsable
    {
        $startedAt = hrtime(true);
        $agent = (new BuyerAssistantAgent(history: $history))->usingProvider($provider);
        $agent->provider();

        $recorder = app(AiRunRecorder::class);
        $turn = $history === [] ? null : 'latest';
        $pattern = $this->guard->match($question);

        if ($pattern === null) {
            foreach ($history as $index => $earlier) {
                if ($earlier->role === 'user' && ($pattern = $this->guard->match($earlier->content)) !== null) {
                    $turn = $index;

                    break;
                }
            }
        }

        if ($pattern !== null) {
            $suffix = $history === [] ? '' : ':'.($turn === 'latest' ? 'latest' : 'turn'.$turn);
            $invocationId = $recorder->recordOutcome(
                'refused',
                class_basename($agent),
                $agent->providerName(),
                $agent->modelName(),
                $agent->promptVersion(),
                intdiv(hrtime(true) - $startedAt, 1_000_000),
                'input:'.pathinfo((string) config('ai.docs.answer.guard'), PATHINFO_FILENAME).':'.$pattern.$suffix,
                origin: self::ORIGIN_API,
                historyTurns: $history === [] ? null : count($history),
            );
            $refusal = ['type' => 'refused', 'stage' => 'input', 'reason' => self::INPUT_REFUSAL, 'pattern' => $pattern, 'replace' => false, 'invocation_id' => $invocationId];

            if ($history !== []) {
                $refusal['turn'] = $turn;
            }

            return new SseEventsResponse([$refusal]);
        }

        $stream = $agent->stream($question)->usingProtocol(new AssistantProtocol($recorder));
        $recorder->annotate($stream->invocationId, [], origin: self::ORIGIN_API, historyTurns: $history === [] ? null : count($history));

        return $stream;
    }
}
