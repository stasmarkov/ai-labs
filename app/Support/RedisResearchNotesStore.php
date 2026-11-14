<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\ResearchNotesStore;
use Illuminate\Contracts\Redis\Factory;

/**
 * The notes of a run in the Redis hash "research:run:{id}:notes", one field per sub-question, kept for a day after the
 * last write. The hash is not deleted when the run ends, so it can be looked at afterwards.
 */
final readonly class RedisResearchNotesStore implements ResearchNotesStore
{
    public function __construct(private Factory $redis) {}

    public static function key(string $runId): string
    {
        return "research:run:{$runId}:notes";
    }

    public function put(string $runId, int $subQuestionIndex, array $note): void
    {
        $connection = $this->redis->connection();
        $key = self::key($runId);

        $connection->hset($key, (string) $subQuestionIndex, json_encode($note, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $connection->expire($key, max(1, (int) config('ai.research.notes_ttl_hours')) * 3600);
    }

    public function all(string $runId): array
    {
        $fields = (array) $this->redis->connection()->hgetall(self::key($runId));
        $notes = [];

        foreach ($fields as $field => $json) {
            $note = json_decode((string) $json, true);

            if (is_array($note)) {
                $notes[(int) $field] = $note;
            }
        }

        ksort($notes);

        return $notes;
    }
}
