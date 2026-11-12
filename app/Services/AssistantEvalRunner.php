<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\BuyerAssistantAgent;
use App\Contracts\AssistantTurnRunnerInterface;
use App\DTOs\AssistantEvalCall;
use App\DTOs\AssistantEvalQuestion;
use App\DTOs\AssistantEvalTurn;
use App\Exceptions\ProviderNotConfiguredException;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolResult;
use Throwable;

/**
 * Plays one question through the real agent and its tool loop, reading the tool calls from the event stream.
 */
final readonly class AssistantEvalRunner implements AssistantTurnRunnerInterface
{
    public function run(AssistantEvalQuestion $question, string $model): AssistantEvalTurn
    {
        $startedAt = hrtime(true);
        $agent = new BuyerAssistantAgent($model, $question->history);
        $calls = [];
        $answer = '';
        $failed = false;

        try {
            foreach ($agent->stream($question->question, model: $agent->hasProviderOverride() ? null : $model) as $event) {
                if ($event instanceof ToolResult) {
                    $calls[] = $this->call($event);
                } elseif ($event instanceof TextDelta) {
                    $answer .= $event->delta;
                } elseif ($event instanceof Error) {
                    $failed = true;
                }
            }
        } catch (ProviderNotConfiguredException $e) {
            throw $e;
        } catch (Throwable) {
            $failed = true;
        }

        return new AssistantEvalTurn(
            $failed ? AssistantEvalTurn::FAILED : AssistantEvalTurn::COMPLETED,
            $calls,
            $answer,
            intdiv(hrtime(true) - $startedAt, 1_000_000),
        );
    }

    private function call(ToolResult $event): AssistantEvalCall
    {
        $arguments = $event->toolResult->arguments;
        $result = is_string($event->toolResult->result) ? json_decode($event->toolResult->result, true) : null;

        if (! $event->successful || ! is_array($result)) {
            return new AssistantEvalCall($arguments, AssistantEvalCall::OTHER);
        }

        if (isset($result['error'])) {
            return new AssistantEvalCall($arguments, $result['error'] === 'invalid_arguments' ? AssistantEvalCall::INVALID : AssistantEvalCall::OTHER);
        }

        $ids = array_map(fn (mixed $listing): mixed => is_array($listing) ? ($listing['id'] ?? null) : null, is_array($result['listings'] ?? null) ? $result['listings'] : []);

        return new AssistantEvalCall($arguments, AssistantEvalCall::OK, array_values(array_filter($ids, is_int(...))));
    }
}
