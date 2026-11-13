<?php

declare(strict_types=1);

namespace App\Ai\AdminTools;

/**
 * Keeps a tool result small. The size is the number of characters of the encoded JSON, wrapper not counted.
 */
final class AdminResultCap
{
    public const MAX_CHARACTERS = 4000;

    /**
     * The encoding every tool result gets on its way to the model. JSON_HEX_TAG turns "<" and ">" inside strings into
     * escapes, so the result cannot close the wrapper tag from the inside.
     *
     * @param  array<string, mixed>  $result
     */
    public static function encode(array $result): string
    {
        return (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Returns the result as it is when it fits. Otherwise `rows` lose entries from the end until it fits, and
     * `truncated` and `rows_dropped` say so. A result with no rows that is still too large is replaced by an error.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function cap(array $result): array
    {
        if (self::fits($result)) {
            return $result;
        }

        $rows = $result['rows'] ?? null;

        if (! is_array($rows)) {
            return ['error' => 'result_too_large', 'truncated' => true];
        }

        $dropped = 0;

        while ($rows !== []) {
            array_pop($rows);
            $dropped++;
            $candidate = $result;
            $candidate['rows'] = $rows;
            $candidate['truncated'] = true;
            $candidate['rows_dropped'] = $dropped;

            if (self::fits($candidate)) {
                return $candidate;
            }
        }

        return ['error' => 'result_too_large', 'truncated' => true];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function fits(array $result): bool
    {
        return mb_strlen(self::encode($result)) <= self::MAX_CHARACTERS;
    }
}
