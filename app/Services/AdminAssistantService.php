<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AdminAssistantLoop;
use App\Ai\AdminConversation;
use App\Ai\AdminConversationStore;
use App\DTOs\AdminReply;
use App\DTOs\AdminTurn;
use App\Exceptions\AdminAssistantConflictException;
use App\Exceptions\AdminAssistantModelException;
use App\Exceptions\AdminAssistantProviderException;

/**
 * The admin assistant as the HTTP layer uses it: a conversation lives in the cache, a write the model proposes waits
 * there as one pending action, and only a decision on that action's id, made by the developer, runs it. A question
 * typed in the chat is never a decision.
 */
final readonly class AdminAssistantService
{
    public function __construct(
        private AdminAssistantLoop $loop,
        private AdminConversationStore $store,
        private AdminAssistantConfirmation $confirmation,
    ) {}

    /**
     * Answers a question. A new conversation starts when no id is given.
     *
     * @throws AdminAssistantConflictException when the conversation is unknown, busy, or waits for a decision
     * @throws AdminAssistantProviderException
     * @throws AdminAssistantModelException
     */
    public function ask(?string $conversationId, string $question): AdminReply
    {
        $conversation = $conversationId === null ? AdminConversation::start() : $this->conversation($conversationId);

        return $this->store->locked($conversation->id, function () use ($conversation, $question): AdminReply {
            $conversation = $this->store->find($conversation->id) ?? $conversation;

            $expired = null;

            if ($conversation->pending !== null) {
                if (! $conversation->pending->expired()) {
                    throw AdminAssistantConflictException::actionPending();
                }

                $this->loop->ensureProviderSupported();
                $expired = $this->confirmation->expire($conversation);
                $this->store->save($conversation);
            }

            try {
                $turn = $this->loop->ask($conversation, $question);
            } catch (AdminAssistantModelException $exception) {
                $this->saveIfStarted($conversation);

                throw $exception;
            }

            $this->store->save($conversation);

            return new AdminReply($conversation->id, $expired === null ? $turn : $turn->withLeadingStep($expired));
        });
    }

    /**
     * Applies or rejects the pending action, then lets the model continue the question it was part of.
     *
     * @throws AdminAssistantConflictException when the conversation or the action is unknown, used, or expired
     * @throws AdminAssistantProviderException when the provider is not Ollama, before the action is touched
     */
    public function decide(string $conversationId, string $actionId, bool $confirm): AdminReply
    {
        $this->loop->ensureProviderSupported();

        return $this->store->locked($conversationId, function () use ($conversationId, $actionId, $confirm): AdminReply {
            $conversation = $this->conversation($conversationId);
            $pending = $conversation->pending;

            if ($pending === null || ! hash_equals($pending->id, $actionId)) {
                throw AdminAssistantConflictException::actionNotPending();
            }

            if ($pending->expired()) {
                $this->confirmation->expire($conversation);
                $this->store->save($conversation);

                throw AdminAssistantConflictException::actionNotPending();
            }

            $conversation->pending = null;
            $decision = $confirm ? $this->confirmation->apply($conversation, $pending) : $this->confirmation->reject($conversation, $pending);
            $this->store->save($conversation);

            try {
                $turn = $this->loop->resume($conversation);
            } catch (AdminAssistantModelException $exception) {
                $turn = new AdminTurn(
                    answer: 'The decision was recorded, but the model could not continue: '.$exception->getMessage(),
                    steps: $exception->steps,
                    runIds: $exception->runIds,
                    code: $exception->reason,
                );
            } catch (AdminAssistantProviderException $exception) {
                $turn = new AdminTurn('The decision was recorded, but the model could not continue: '.$exception->getMessage(), code: AdminAssistantProviderException::CODE);
            }

            $this->store->save($conversation);

            return new AdminReply($conversation->id, new AdminTurn(
                answer: $turn->answer,
                steps: [$decision, ...$turn->steps],
                pending: $turn->pending,
                limitReached: $turn->limitReached,
                runIds: $turn->runIds,
                code: $turn->code,
            ));
        });
    }

    /**
     * After a failed question: a conversation that has messages is saved, so the exchanges that completed are kept.
     * One that has none was never stored and is not stored now.
     */
    private function saveIfStarted(AdminConversation $conversation): void
    {
        if ($conversation->messages !== [] || $this->store->find($conversation->id) !== null) {
            $this->store->save($conversation);
        }
    }

    /**
     * @throws AdminAssistantConflictException
     */
    private function conversation(string $id): AdminConversation
    {
        return $this->store->find($id) ?? throw AdminAssistantConflictException::conversationNotFound();
    }
}
