<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\AdminTools\AdminResultCap;
use App\Ai\AdminTools\AdminTool;
use App\Ai\AdminTools\AdminToolFailed;
use App\Ai\AdminTools\AdminToolRegistry;
use App\Ai\AdminTools\AdminWriteTool;
use App\Ai\AdminTools\SearchListingsTool;
use App\DTOs\AdminChatCompletion;
use App\DTOs\AdminPendingAction;
use App\DTOs\AdminStepReport;
use App\DTOs\AdminToolCall;
use App\DTOs\AdminTurn;
use App\DTOs\AiToolStepRecord;
use App\Exceptions\AdminAssistantModelException;
use App\Exceptions\AdminAssistantProviderException;
use App\Services\PromptInjectionGuard;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * The agent loop, written by hand: send the messages and the tool definitions, read the tool_calls of the answer,
 * check each call in PHP, run the read tools, stop at a write, feed the results back, repeat.
 *
 * Cost is bounded three ways: at most MAX_STEPS handled tool calls and MAX_CALLS model calls per question, and no new
 * normal call once MAX_SECONDS have passed. When a cap is reached the next call is the last one, sent with
 * tool_choice "none", so the model can only answer.
 */
final readonly class AdminAssistantLoop implements VersionedPrompt
{
    public const MAX_STEPS = 4;

    public const MAX_CALLS = 6;

    public const MAX_SECONDS = 90;

    public const MAX_HISTORY = 40;

    /** Tool calls of one answer that are looked at; every call is answered, but only these get a step row. */
    public const MAX_CALLS_PER_ANSWER = self::MAX_STEPS + 4;

    public const PENDING_MINUTES = 10;

    private const MAX_ARGUMENT_LENGTH = 200;

    private const MAX_ERROR_DETAILS = 10;

    private const REFUSAL = 'That request looks like an attempt to change my instructions, so it was not sent to the model.';

    private const LIMIT_ANSWER = 'I reached the step limit; ask a narrower question.';

    private const NO_ANSWER = 'The model returned no answer.';

    public function __construct(
        private AdminToolRegistry $tools,
        private OllamaChatClient $client,
        private AiProviders $providers,
        private PromptInjectionGuard $guard,
    ) {}

    public function promptVersion(): string
    {
        $name = $this->promptName();
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr_replace($name, '@', $dot, 1);
    }

    /**
     * Answers a new question of the conversation. A question the guard matches is refused before any model call.
     *
     * Partial state, the same rule on ask and resume: when a model call fails, the conversation keeps every complete
     * exchange (an assistant message with its tool messages) and the caller saves it. A question that got no exchange at
     * all is taken back, so it can be asked again.
     *
     * @throws AdminAssistantProviderException
     * @throws AdminAssistantModelException
     */
    public function ask(AdminConversation $conversation, string $question): AdminTurn
    {
        $choice = $this->choice();
        $pattern = $this->guard->match($question);

        if ($pattern !== null) {
            return new AdminTurn(self::REFUSAL, refused: true, pattern: $pattern);
        }

        $before = [$conversation->messages, $conversation->steps, $conversation->calls, $conversation->position];

        $conversation->steps = 0;
        $conversation->calls = 0;
        $conversation->position = 0;
        $conversation->messages[] = ['role' => 'user', 'content' => $question];

        try {
            return $this->drive($conversation, $choice);
        } catch (AdminAssistantModelException $exception) {
            if (count($conversation->messages) === count($before[0]) + 1) {
                [$conversation->messages, $conversation->steps, $conversation->calls, $conversation->position] = $before;
            }

            throw $exception;
        }
    }

    /**
     * Continues the question after a decision on a pending write, once the decision's tool message is in the history.
     *
     * @throws AdminAssistantProviderException
     * @throws AdminAssistantModelException
     */
    public function resume(AdminConversation $conversation): AdminTurn
    {
        return $this->drive($conversation, $this->choice());
    }

    /**
     * The messages as a tool message carries a result back: JSON inside <tool_result> tags, marked as data.
     *
     * @param  array<string, mixed>  $result
     * @return array{role: string, tool_call_id: string, content: string}
     */
    public static function toolMessage(string $toolCallId, array $result): array
    {
        return ['role' => 'tool', 'tool_call_id' => $toolCallId, 'content' => '<tool_result>'.AdminResultCap::encode($result).'</tool_result>'];
    }

    /**
     * Fails when the admin assistant provider is not Ollama, so a decision can be refused before it changes anything.
     *
     * @throws AdminAssistantProviderException
     */
    public function ensureProviderSupported(): void
    {
        $this->choice();
    }

    /**
     * @throws AdminAssistantProviderException
     */
    private function choice(): ProviderChoice
    {
        $choice = $this->providers->effective(AiProviders::ADMIN_ASSISTANT)->choice;

        if ($choice->driver !== 'ollama') {
            throw AdminAssistantProviderException::named($choice->provider);
        }

        return $choice;
    }

    /**
     * @throws AdminAssistantModelException
     */
    private function drive(AdminConversation $conversation, ProviderChoice $choice): AdminTurn
    {
        $startedAt = now();
        $reports = [];
        $runIds = [];
        $limitReached = false;
        $latencyMs = 0;

        while (true) {
            $final = $conversation->steps >= self::MAX_STEPS
                || $conversation->calls >= self::MAX_CALLS - 1
                || $startedAt->diffInSeconds(now(), true) >= self::MAX_SECONDS;
            $limitReached = $limitReached || $final;

            $runId = (string) Str::uuid();
            $runIds[] = $runId;
            $conversation->calls++;

            try {
                $completion = $this->call($conversation, $choice, $runId, $final ? 'none' : 'auto', $latencyMs);
            } catch (AdminAssistantModelException $exception) {
                throw $exception->withProgress($reports, $runIds);
            }

            $content = $this->clean($completion->content);

            if ($final || $completion->toolCalls === []) {
                $records = [];

                foreach ($final ? array_slice($completion->toolCalls, 0, self::MAX_CALLS_PER_ANSWER) : [] as $call) {
                    [$records[], $reports[]] = $this->refuse($conversation, $call, $runId, 'step_limit', ['status' => 'not_run', 'reason' => 'step_limit'], addMessage: false);
                }

                $conversation->messages[] = ['role' => 'assistant', 'content' => $content];
                $this->record($choice, $runId, $latencyMs, $completion, $records);

                return new AdminTurn(
                    answer: $content !== '' ? $content : ($final ? self::LIMIT_ANSWER : self::NO_ANSWER),
                    steps: $reports,
                    limitReached: $limitReached,
                    runIds: $runIds,
                );
            }

            $conversation->messages[] = [
                'role' => 'assistant',
                'content' => $content,
                'tool_calls' => array_map(fn (AdminToolCall $call): array => $call->toMessage(), $completion->toolCalls),
            ];

            $pending = null;
            $records = [];

            foreach ($completion->toolCalls as $index => $call) {
                if ($index >= self::MAX_CALLS_PER_ANSWER) {
                    $conversation->messages[] = self::toolMessage($call->id, ['status' => 'not_run', 'reason' => 'too_many_calls']);
                    $limitReached = true;

                    continue;
                }

                [$record, $report, $proposal, $blocked] = $this->handle($conversation, $call, $runId, $pending !== null);
                $records[] = $record;
                $reports[] = $report;
                $pending ??= $proposal;
                $limitReached = $limitReached || $blocked;
            }

            $this->record($choice, $runId, $latencyMs, $completion, $records);

            if ($pending !== null) {
                $conversation->pending = $pending;

                return new AdminTurn($content, $reports, $pending, $limitReached, $runIds);
            }
        }
    }

    /**
     * One model call. A failed call is logged as a failed run before the exception goes on.
     *
     * @throws AdminAssistantModelException
     */
    private function call(AdminConversation $conversation, ProviderChoice $choice, string $runId, string $toolChoice, int &$latencyMs): AdminChatCompletion
    {
        $startedAt = hrtime(true);

        try {
            $completion = $this->client->complete(
                $choice->provider,
                $choice->model,
                [['role' => 'system', 'content' => $this->instructions()], ...$this->window($conversation->messages)],
                $this->tools->definitions(),
                $toolChoice,
            );
        } catch (AdminAssistantModelException $exception) {
            $latencyMs = intdiv(hrtime(true) - $startedAt, 1_000_000);
            app(AiRunRecorder::class)->recordRun(
                'failed', 'AdminAssistantLoop', $choice->provider, $choice->model, $this->promptVersion(), $latencyMs, null,
                invocationId: $runId, exception: $exception, origin: 'api',
            );

            throw $exception;
        }

        $latencyMs = intdiv(hrtime(true) - $startedAt, 1_000_000);

        return $completion;
    }

    /**
     * @param  list<AiToolStepRecord>  $records
     */
    private function record(ProviderChoice $choice, string $runId, int $latencyMs, AdminChatCompletion $completion, array $records): void
    {
        app(AiRunRecorder::class)->recordRun(
            'completed', 'AdminAssistantLoop', $choice->provider, $choice->model, $this->promptVersion(), $latencyMs, null,
            tokensIn: $completion->tokensIn, tokensOut: $completion->tokensOut, invocationId: $runId, origin: 'api', toolSteps: $records,
        );
    }

    /**
     * Handles one tool call of an answer in the order the model gave it. Returns the logged step, the timeline row, the
     * pending action when the call was a valid write, and whether the step cap blocked the call.
     *
     * @return array{0: AiToolStepRecord, 1: AdminStepReport, 2: ?AdminPendingAction, 3: bool}
     */
    private function handle(AdminConversation $conversation, AdminToolCall $call, string $runId, bool $writePending): array
    {
        if ($conversation->steps >= self::MAX_STEPS) {
            return [...$this->refuse($conversation, $call, $runId, 'step_limit', ['status' => 'not_run', 'reason' => 'step_limit']), null, true];
        }

        $conversation->steps++;

        if ($writePending) {
            return [...$this->refuse($conversation, $call, $runId, 'deferred', ['status' => 'deferred', 'reason' => 'one change at a time; ask again after the decision']), null, false];
        }

        $startedAt = hrtime(true);
        $tool = $this->tools->find($call->name);
        $arguments = $this->decode($call->arguments);

        if ($tool === null) {
            return [...$this->refuse($conversation, $call, $runId, 'unknown_tool', ['error' => 'unknown_tool', 'details' => ['Use one of: '.implode(', ', array_map(fn (AdminTool $each): string => $each->name(), $this->tools->all())).'.']]), null, false];
        }

        if ($arguments === null) {
            return [...$this->refuse($conversation, $call, $runId, 'invalid_arguments', ['error' => 'invalid_arguments', 'details' => ['The arguments are not a valid JSON object.']]), null, false];
        }

        $errors = $tool->validate($arguments);

        if ($errors !== []) {
            return [...$this->refuse($conversation, $call, $runId, 'invalid_arguments', ['error' => 'invalid_arguments', 'details' => array_slice($errors, 0, self::MAX_ERROR_DETAILS)]), null, false];
        }

        $position = ++$conversation->position;

        if ($tool->isWrite()) {
            if (! $tool instanceof AdminWriteTool) {
                throw new LogicException("The write tool {$tool->name()} cannot describe its change.");
            }

            $preview = $tool->preview($arguments);
            $pending = new AdminPendingAction(
                id: Str::random(32),
                tool: $tool->name(),
                arguments: $arguments,
                toolCallId: $call->id,
                preview: $preview,
                expiresAt: now()->toImmutable()->addMinutes(self::PENDING_MINUTES),
                runId: $runId,
            );

            return [
                $this->logged($position, $call->name, $arguments, 'pending', null, $this->summary($preview), $startedAt),
                $this->reported($position, $call->name, $arguments, 'pending', null, $preview, $startedAt, $runId),
                $pending,
                false,
            ];
        }

        try {
            $reply = $this->tools->runRead($tool, $arguments);
            $outcome = 'ok';
            $error = null;
        } catch (AdminToolFailed $exception) {
            $outcome = 'error';
            $error = 'tool_failed';
            $reply = ['error' => 'tool_failed', 'details' => [mb_substr($exception->getMessage(), 0, 200)]];
        } catch (Throwable $exception) {
            report($exception);
            $outcome = 'failed';
            $error = $exception::class;
            $reply = ['status' => 'failed'];
        }

        $conversation->messages[] = self::toolMessage($call->id, $reply);

        return [
            $this->logged($position, $call->name, $arguments, $outcome, $error, $this->summary($reply), $startedAt),
            $this->reported($position, $call->name, $arguments, $outcome, $error, $reply, $startedAt, $runId),
            null,
            false,
        ];
    }

    /**
     * Answers a call without running it: the model gets the reply as the call's tool message, the step is logged as an
     * error. Whether it counts against the step cap is for the caller to decide.
     *
     * @param  array<string, mixed>  $reply
     * @return array{0: AiToolStepRecord, 1: AdminStepReport}
     */
    private function refuse(AdminConversation $conversation, AdminToolCall $call, string $runId, string $error, array $reply, bool $addMessage = true): array
    {
        $startedAt = hrtime(true);
        $position = ++$conversation->position;
        $arguments = $this->decode($call->arguments) ?? ['raw' => $call->arguments];

        if ($addMessage) {
            $conversation->messages[] = self::toolMessage($call->id, $reply);
        }

        return [
            $this->logged($position, $call->name, $arguments, 'error', $error, null, $startedAt),
            $this->reported($position, $call->name, $arguments, 'error', $error, $reply, $startedAt, $runId),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $summary
     */
    private function logged(int $position, string $tool, array $arguments, string $outcome, ?string $error, ?array $summary, int $startedAt): AiToolStepRecord
    {
        return new AiToolStepRecord(
            position: $position,
            tool: mb_substr($tool, 0, 120),
            arguments: $this->shortened($arguments),
            outcome: $outcome,
            error: $error === null ? null : mb_substr($error, 0, 120),
            resultSummary: $summary,
            durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $result
     */
    private function reported(int $position, string $tool, array $arguments, string $outcome, ?string $error, ?array $result, int $startedAt, string $runId): AdminStepReport
    {
        return new AdminStepReport(
            position: $position,
            tool: $tool,
            arguments: $this->shortened($arguments),
            outcome: $outcome,
            error: $error,
            result: $result,
            durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
            runId: $runId,
        );
    }

    /**
     * What the log keeps of a result: counts and ids, never the rows.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function summary(array $result): array
    {
        $summary = array_intersect_key($result, array_flip(['total', 'id', 'tag', 'count', 'old_title', 'new_title', 'changed', 'already_tagged', 'status', 'error']));

        if (is_array($result['rows'] ?? null)) {
            $summary['shown'] = count($result['rows']);
            $summary['listing_ids'] = array_slice(array_values(array_filter(
                array_map(fn (mixed $row): mixed => is_array($row) ? ($row['id'] ?? null) : null, $result['rows']),
                is_int(...),
            )), 0, SearchListingsTool::MAX_LIMIT);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function shortened(array $arguments): array
    {
        return array_map(fn (mixed $value): mixed => is_string($value) ? mb_substr($value, 0, self::MAX_ARGUMENT_LENGTH) : $value, $arguments);
    }

    /**
     * The arguments of a call, or null when the model did not send a JSON object. Empty text means no arguments.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $json): ?array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && ($decoded === [] || ! array_is_list($decoded)) ? $decoded : null;
    }

    private function clean(string $content): string
    {
        $content = (string) preg_replace('/<think>.*?<\/think>/s', '', $content);
        $content = (string) preg_replace('/^.*?<\/think>/s', '', $content);
        $content = (string) preg_replace('/<think>.*$/s', '', $content);

        return trim($content);
    }

    /**
     * The messages the model sees: the last MAX_HISTORY, cut pair-safe (see AdminConversation::window()).
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function window(array $messages): array
    {
        return AdminConversation::window($messages, self::MAX_HISTORY);
    }

    private function instructions(): string
    {
        return trim((string) file_get_contents(resource_path('prompts/'.$this->promptName().'.md')));
    }

    private function promptName(): string
    {
        return (string) config('ai.prompts.admin_assistant');
    }
}
