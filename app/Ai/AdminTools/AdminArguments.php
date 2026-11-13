<?php

declare(strict_types=1);

namespace App\Ai\AdminTools;

use Illuminate\Support\Facades\Validator;

/**
 * The argument rules the five tools share. Arguments come from model-written JSON, so they are never trusted: blanks
 * are dropped, strings are trimmed, and integers must really be integers.
 */
final class AdminArguments
{
    public const TAG_MIN = 2;

    public const TAG_MAX = 30;

    public const MAX_TAGS = 20;

    private const TAG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * The HTTP path trims strings and turns blanks into null in middleware; a tool call skips that, so it happens
     * here. Keys in $raw keep their exact value, so a rule can see what the model really sent.
     *
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $raw
     * @return array<string, mixed>
     */
    public static function normalise(array $arguments, array $raw = []): array
    {
        $clean = [];

        foreach ($arguments as $key => $value) {
            if (is_string($value) && ! in_array($key, $raw, true)) {
                $value = trim($value);
            }

            if ($value !== '' && $value !== null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * The tag as it is stored: trimmed and lower-cased. Null for anything that is not a string.
     */
    public static function tag(mixed $value): ?string
    {
        return is_string($value) ? mb_strtolower(trim($value)) : null;
    }

    /**
     * A listing's tags for the model: the first MAX_TAGS, and the full count beside them.
     *
     * @param  list<string>  $tags
     * @return array{tags: list<string>, tags_total: int}
     */
    public static function tagsShown(array $tags): array
    {
        return ['tags' => array_slice($tags, 0, self::MAX_TAGS), 'tags_total' => count($tags)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public static function unknown(array $arguments, array $allowed): array
    {
        $errors = [];

        foreach (array_keys($arguments) as $field) {
            if (! in_array($field, $allowed, true)) {
                $errors[] = "The {$field} argument is not supported.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    public static function idErrors(array $arguments): array
    {
        $id = $arguments['id'] ?? null;

        if ($id === null) {
            return ['The id argument is required.'];
        }

        if (! is_int($id) || $id < 1) {
            return ['The id argument must be a positive integer.'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    public static function tagErrors(array $arguments, bool $required): array
    {
        $value = $arguments['tag'] ?? null;

        if ($value === null) {
            return $required ? ['The tag argument is required.'] : [];
        }

        $tag = self::tag($value);

        if ($tag === null) {
            return ['The tag argument must be a string.'];
        }

        $length = mb_strlen($tag);

        if ($length < self::TAG_MIN || $length > self::TAG_MAX || preg_match(self::TAG_PATTERN, $tag) !== 1) {
            return ['The tag argument must be '.self::TAG_MIN.' to '.self::TAG_MAX.' characters: lower-case letters and digits, with single hyphens between them.'];
        }

        return [];
    }

    /**
     * Runs Laravel rules and flattens the messages.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, array<int, mixed>>  $rules
     * @return list<string>
     */
    public static function ruleErrors(array $arguments, array $rules): array
    {
        $messages = [];

        foreach (Validator::make($arguments, $rules)->errors()->all() as $message) {
            $messages[] = $message;
        }

        return $messages;
    }
}
