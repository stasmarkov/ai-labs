<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Detects an answer that repeats the system prompt: the request's canary, or a run of WORD_RUN consecutive prompt
 * words that the request's own context and question do not also contain (quoting a retrieved chunk is honest).
 */
final class OutputGuard
{
    public const WORD_RUN = 12;

    /** @var array<string, true> */
    private array $protectedRuns = [];

    /**
     * @param  list<string>  $allowedTexts  the chunk bodies and the question of this request
     */
    public function __construct(private readonly string $canary, string $systemPrompt, array $allowedTexts)
    {
        $allowed = [];

        foreach ($allowedTexts as $text) {
            foreach (self::runs(self::words($text)) as $run) {
                $allowed[$run] = true;
            }
        }

        foreach (self::runs(self::words($systemPrompt)) as $run) {
            if (! isset($allowed[$run])) {
                $this->protectedRuns[$run] = true;
            }
        }
    }

    /**
     * "canary" or "prompt_overlap" when the text so far leaks the prompt, else null.
     */
    public function leak(string $text): ?string
    {
        if (str_contains($text, $this->canary)) {
            return 'canary';
        }

        foreach (self::runs(self::words($text)) as $run) {
            if (isset($this->protectedRuns[$run])) {
                return 'prompt_overlap';
            }
        }

        return null;
    }

    /**
     * Lowercased letter and digit runs, with [n] citation markers removed first so a marker between two copied
     * clauses does not break the run.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $text = preg_replace('/\[\d+(?:\s*,\s*\d+)*\]/u', ' ', $text) ?? $text;

        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private static function runs(array $words): array
    {
        $runs = [];

        for ($i = 0; $i + self::WORD_RUN <= count($words); $i++) {
            $runs[] = implode(' ', array_slice($words, $i, self::WORD_RUN));
        }

        return $runs;
    }
}
