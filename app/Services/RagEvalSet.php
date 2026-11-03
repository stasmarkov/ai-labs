<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\RagEvalExpectation;
use App\DTOs\RagEvalQuestion;
use App\Exceptions\RagEvalSetException;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Loads and validates the hand-labelled evaluation questions (one JSON object per line).
 */
final readonly class RagEvalSet
{
    public const VERSION = 'v1';

    private const TYPES = [RagEvalQuestion::ON_TOPIC, RagEvalQuestion::OFF_TOPIC];

    private const MAX_EVIDENCE_WORDS = 12;

    public static function defaultPath(): string
    {
        return base_path('bench/rag-eval/questions.'.self::VERSION.'.jsonl');
    }

    /**
     * Lowercases, drops the markdown characters ` * _ # > | and collapses whitespace, so a phrase can be compared with raw markdown.
     */
    public static function normalizeEvidence(string $text): string
    {
        $text = str_replace(['`', '*', '_', '#', '>', '|'], '', mb_strtolower($text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Lowercases and collapses whitespace.
     */
    public static function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    /**
     * @return list<RagEvalQuestion>
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

    private function parse(string $raw, int $line): RagEvalQuestion
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw RagEvalSetException::atLine($line, 'invalid JSON ('.$exception->getMessage().')');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw RagEvalSetException::atLine($line, 'expected a JSON object');
        }

        $id = $this->string($data, 'id', $line);

        if (preg_match('/\A[a-z0-9-]{3,40}\z/', $id) !== 1) {
            throw RagEvalSetException::atLine($line, 'id must match [a-z0-9-]{3,40}');
        }

        $type = $this->string($data, 'type', $line);

        if (! in_array($type, self::TYPES, true)) {
            throw RagEvalSetException::atLine($line, 'type must be on_topic or off_topic');
        }

        $expected = $this->expectations($data['expected'] ?? null, $line);

        if ($type === RagEvalQuestion::ON_TOPIC && $expected === []) {
            throw RagEvalSetException::atLine($line, 'an on_topic question needs at least one expected document');
        }

        if ($type === RagEvalQuestion::OFF_TOPIC && $expected !== []) {
            throw RagEvalSetException::atLine($line, 'an off_topic question must have no expected documents');
        }

        return new RagEvalQuestion(
            $id,
            $type,
            $this->string($data, 'question', $line),
            $this->string($data, 'answer', $line),
            $expected,
            $this->tags($data['tags'] ?? [], $line),
            $line,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(array $data, string $key, int $line): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw RagEvalSetException::atLine($line, "\"{$key}\" must be a non-empty string");
        }

        return $value;
    }

    /**
     * @return list<RagEvalExpectation>
     */
    private function expectations(mixed $value, int $line): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw RagEvalSetException::atLine($line, '"expected" must be a list');
        }

        $expectations = [];

        foreach ($value as $item) {
            $doc = is_array($item) ? ($item['doc'] ?? null) : null;

            if (! is_string($doc) || preg_match('/\A(repo|laravel):(.+)\z/', $doc, $parts) !== 1) {
                throw RagEvalSetException::atLine($line, 'each expected "doc" must look like source:path');
            }

            $phrases = $item['evidence'] ?? null;

            if (! is_array($phrases) || ! array_is_list($phrases) || $phrases === []) {
                throw RagEvalSetException::atLine($line, "\"evidence\" of {$doc} must be a non-empty list");
            }

            $clean = [];

            foreach ($phrases as $phrase) {
                $words = is_string($phrase) ? preg_split('/\s+/u', trim($phrase), -1, PREG_SPLIT_NO_EMPTY) : false;

                if ($words === false || $words === [] || count($words) > self::MAX_EVIDENCE_WORDS) {
                    throw RagEvalSetException::atLine($line, "an evidence phrase of {$doc} must be 1 to ".self::MAX_EVIDENCE_WORDS.' words');
                }

                if (self::normalizeEvidence($phrase) === '') {
                    throw RagEvalSetException::atLine($line, "an evidence phrase of {$doc} has no text left after markdown characters are removed");
                }

                $clean[] = $phrase;
            }

            $expectations[] = new RagEvalExpectation($parts[1], $parts[2], $clean);
        }

        return $expectations;
    }

    /**
     * @return list<string>
     */
    private function tags(mixed $value, int $line): array
    {
        if (! is_array($value) || ! array_is_list($value) || array_filter($value, fn (mixed $tag): bool => ! is_string($tag)) !== []) {
            throw RagEvalSetException::atLine($line, '"tags" must be a list of strings');
        }

        return $value;
    }
}
