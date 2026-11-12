<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\AssistantEvalQuestion;
use App\DTOs\DocsChatTurn;
use App\Exceptions\RagEvalSetException;
use App\Rules\ListingFilterRules;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Loads and validates the labelled assistant questions (one JSON object per line).
 */
final readonly class AssistantEvalSet
{
    public const VERSION = 'v1';

    public static function defaultPath(): string
    {
        return base_path('bench/assistant/questions.'.self::VERSION.'.jsonl');
    }

    /**
     * @return list<AssistantEvalQuestion>
     *
     * @throws RagEvalSetException
     */
    public function load(?string $path = null): array
    {
        $path ??= self::defaultPath();

        if (! is_file($path)) {
            throw RagEvalSetException::missing($path);
        }

        $questions = [];
        $ids = [];

        foreach (preg_split('/\R/', File::get($path)) ?: [] as $index => $raw) {
            $line = $index + 1;

            if (trim($raw) === '') {
                continue;
            }

            $question = $this->parse($raw, $line);

            if (isset($ids[$question->id])) {
                throw RagEvalSetException::atLine($line, "duplicate id \"{$question->id}\" (first on line {$ids[$question->id]})");
            }

            $ids[$question->id] = $line;
            $questions[] = $question;
        }

        return $questions;
    }

    private function parse(string $raw, int $line): AssistantEvalQuestion
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw RagEvalSetException::atLine($line, 'invalid JSON ('.$exception->getMessage().')');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw RagEvalSetException::atLine($line, 'expected a JSON object');
        }

        $id = $data['id'] ?? null;

        if (! is_string($id) || preg_match('/\A[a-z0-9-]{3,40}\z/', $id) !== 1) {
            throw RagEvalSetException::atLine($line, 'id must match [a-z0-9-]{3,40}');
        }

        $text = $data['question'] ?? null;

        if (! is_string($text) || trim($text) === '') {
            throw RagEvalSetException::atLine($line, '"question" must be a non-empty string');
        }

        $expectsTool = $data['expects_tool'] ?? null;

        if (! is_bool($expectsTool)) {
            throw RagEvalSetException::atLine($line, '"expects_tool" must be a boolean');
        }

        $maxSteps = $data['max_steps_ok'] ?? null;

        if (! is_int($maxSteps) || $maxSteps < 0 || $maxSteps > 4) {
            throw RagEvalSetException::atLine($line, '"max_steps_ok" must be an integer from 0 to 4');
        }

        $filters = $this->filters($data['expected_filters'] ?? null, $line);

        if ($expectsTool === ($filters === [])) {
            throw RagEvalSetException::atLine($line, '"expected_filters" must be non-empty exactly when "expects_tool" is true');
        }

        return new AssistantEvalQuestion($id, $text, $expectsTool, $filters, $maxSteps, $this->history($data['history'] ?? [], $line), $this->unsupported($data['unsupported'] ?? [], $expectsTool, $line));
    }

    /**
     * @return array<string, string|int>
     */
    private function filters(mixed $value, int $line): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw RagEvalSetException::atLine($line, '"expected_filters" must be an object');
        }

        foreach ($value as $field => $item) {
            $isNumber = in_array($field, ['min_year', 'max_price'], true);

            if (! array_key_exists($field, ListingFilterRules::rules()) || ! ($isNumber ? is_int($item) : is_string($item) && trim($item) !== '')) {
                throw RagEvalSetException::atLine($line, "expected filter \"{$field}\" is not a search field or has the wrong type");
            }
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function unsupported(mixed $value, bool $expectsTool, int $line): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw RagEvalSetException::atLine($line, '"unsupported" must be a list of words');
        }

        if ($value !== [] && ! $expectsTool) {
            throw RagEvalSetException::atLine($line, '"unsupported" is only allowed on a question that expects the tool');
        }

        foreach ($value as $word) {
            if (! is_string($word) || trim($word) === '') {
                throw RagEvalSetException::atLine($line, '"unsupported" must hold non-empty strings');
            }
        }

        return array_map(fn (string $word): string => mb_strtolower(trim($word)), $value);
    }

    /**
     * @return list<DocsChatTurn>
     */
    private function history(mixed $value, int $line): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw RagEvalSetException::atLine($line, '"history" must be a list of turns');
        }

        return array_map(function (mixed $turn) use ($line): DocsChatTurn {
            if (! is_array($turn) || ! in_array($turn['role'] ?? null, ['user', 'assistant'], true) || ! is_string($turn['content'] ?? null) || trim($turn['content']) === '') {
                throw RagEvalSetException::atLine($line, 'a history turn needs a role (user or assistant) and non-empty content');
            }

            return new DocsChatTurn($turn['role'], $turn['content']);
        }, $value);
    }
}
