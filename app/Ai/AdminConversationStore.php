<?php

declare(strict_types=1);

namespace App\Ai;

use App\DTOs\AdminPendingAction;
use App\Exceptions\AdminAssistantConflictException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use TypeError;

/**
 * Keeps admin-assistant conversations in the cache, on the server. A conversation is stored as a snapshot, so a
 * change that is not saved is not seen by the next request, and one request at a time works on a conversation.
 */
final readonly class AdminConversationStore
{
    public const TTL_MINUTES = 60;

    /** The messages a stored conversation keeps; older ones are cut at save, pair-safe. */
    public const MAX_STORED_MESSAGES = 80;

    /** The lock is held for the whole request, model calls included, so it outlives the 150 s time limit; it is released when the request ends. */
    private const LOCK_SECONDS = 300;

    /** How long a second request of the same conversation waits for the lock before it gets 409 conversation_busy. */
    private const LOCK_WAIT_SECONDS = 5;

    /** The only classes a stored conversation may contain. */
    private const ALLOWED_CLASSES = [AdminConversation::class, AdminPendingAction::class, CarbonImmutable::class];

    public function find(string $id): ?AdminConversation
    {
        $raw = Cache::get($this->key($id));

        if (! is_string($raw)) {
            return null;
        }

        try {
            $conversation = unserialize($raw, ['allowed_classes' => self::ALLOWED_CLASSES]);
        } catch (TypeError) {
            return null;
        }

        if (! $conversation instanceof AdminConversation) {
            return null;
        }

        $plain = true;
        array_walk_recursive($conversation->messages, function (mixed $value) use (&$plain): void {
            $plain = $plain && ! is_object($value);
        });

        return $plain ? $conversation : null;
    }

    public function save(AdminConversation $conversation): void
    {
        $conversation->messages = AdminConversation::window($conversation->messages, self::MAX_STORED_MESSAGES);
        Cache::put($this->key($conversation->id), serialize($conversation), now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * Runs the callback while holding the conversation's lock.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws AdminAssistantConflictException when the lock is not free in time
     */
    public function locked(string $id, Closure $callback): mixed
    {
        try {
            return Cache::lock($this->key($id).':lock', self::LOCK_SECONDS)->block((int) config('ai.admin_assistant.lock_wait_seconds', self::LOCK_WAIT_SECONDS), $callback);
        } catch (LockTimeoutException) {
            throw AdminAssistantConflictException::busy();
        }
    }

    private function key(string $id): string
    {
        return 'admin-assistant:conversation:'.$id;
    }
}
