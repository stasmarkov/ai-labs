<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DocsMatch;
use App\Enums\DocsSearchMode;

final readonly class DocsAnswerContext
{
    public function __construct(private DocsSearchService $search) {}

    /**
     * The chunks that may ground an answer: hybrid search, relevance gate, token budget.
     *
     * @return list<DocsMatch>
     */
    public function build(string $question): array
    {
        return $this->limit($this->retrieve($question), $question);
    }

    /**
     * What hybrid search returns for the question, before the relevance gate and the budget.
     *
     * @return list<DocsMatch>
     */
    public function retrieve(string $question): array
    {
        return $this->search->search($question, DocsSearchMode::Hybrid, max(1, (int) config('ai.docs.answer.k')));
    }

    /**
     * The relevance gate and the token budget, with the configured limits.
     *
     * @param  list<DocsMatch>  $matches  best first
     * @return list<DocsMatch>
     */
    public function limit(array $matches, string $question): array
    {
        return self::select(
            $matches,
            (float) config('ai.docs.answer.min_similarity'),
            max(1, (int) config('ai.docs.answer.max_context_tokens')),
            ! self::usesSearchOperators($question),
        );
    }

    /**
     * Full-text search reads the question with websearch_to_tsquery, where the word "or" means OR and "-word" means NOT.
     * Without them a full-text hit means every question word is in the chunk; with them one word can be enough.
     */
    public static function usesSearchOperators(string $question): bool
    {
        return preg_match('/(?:^|\s)(?:or(?=\s|$)|-(?=\S))/iu', $question) === 1;
    }

    /**
     * @param  list<DocsMatch>  $matches  best first
     * @param  bool  $textHitsCount  whether a full-text hit alone passes the gate
     * @return list<DocsMatch>
     */
    public static function select(array $matches, float $minSimilarity, int $maxTokens, bool $textHitsCount = true): array
    {
        $relevant = array_values(array_filter(
            $matches,
            fn (DocsMatch $match): bool => ($match->vectorSimilarity !== null && $match->vectorSimilarity >= $minSimilarity)
                || ($textHitsCount && $match->textRank !== null),
        ));

        return self::fitBudget($relevant, $maxTokens);
    }

    /**
     * Keeps chunks in rank order until the budget is spent, dropping the lowest ranked; the top chunk is always kept, cut to the budget.
     *
     * @param  list<DocsMatch>  $matches
     * @return list<DocsMatch>
     */
    private static function fitBudget(array $matches, int $maxTokens): array
    {
        $kept = [];
        $remaining = $maxTokens;

        foreach ($matches as $index => $match) {
            $tokens = self::tokens($match->body);

            if ($index === 0 && $tokens > $remaining) {
                $kept[] = self::withBody($match, self::cutAtWhitespace($match->body, $remaining * 4));

                return $kept;
            }

            if ($tokens > $remaining) {
                break;
            }

            $kept[] = $match;
            $remaining -= $tokens;
        }

        return $kept;
    }

    /**
     * Cuts to at most $limit code points, backing up to the last whitespace in that window; hard cut when it has none.
     */
    private static function cutAtWhitespace(string $text, int $limit): string
    {
        $window = mb_substr($text, 0, $limit);

        if (mb_strlen($text) <= $limit || preg_match('/^(.*\S)\s/su', $window, $found) !== 1) {
            return $window;
        }

        return $found[1];
    }

    private static function tokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    private static function withBody(DocsMatch $match, string $body): DocsMatch
    {
        return new DocsMatch($match->id, $match->source, $match->path, $match->heading, $body, $match->score, $match->vectorRank, $match->vectorSimilarity, $match->textRank, $match->textScore);
    }
}
