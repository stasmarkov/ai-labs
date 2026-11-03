<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DocsMatch;
use App\DTOs\RagEvalQuestion;
use App\DTOs\RagRecallCell;
use App\DTOs\RagRetrievalResult;
use App\Enums\DocsSearchMode;
use App\Exceptions\DocsSearchUnavailableException;
use App\Exceptions\RagEvalOptionException;

/**
 * Recall@k and MRR of the docs retrieval modes over the eval set, plus each question's top vector similarity.
 */
final readonly class RagRetrievalEvaluator
{
    public function __construct(private DocsSearchService $search) {}

    /**
     * Each question is embedded once and each mode searched once at the largest k; smaller k are cut from that list.
     *
     * @param  list<RagEvalQuestion>  $questions
     * @param  list<DocsSearchMode>  $modes
     * @param  list<int>  $ks
     *
     * @throws RagEvalOptionException when a k is larger than ai.docs.rrf_depth, or no k or mode is given.
     * @throws DocsSearchUnavailableException when a question cannot be embedded.
     */
    public function evaluate(array $questions, array $modes, array $ks): RagRetrievalResult
    {
        $ks = array_values(array_unique($ks));
        sort($ks);

        if ($ks === []) {
            throw RagEvalOptionException::noKs();
        }

        if ($modes === []) {
            throw RagEvalOptionException::noModes();
        }

        $largest = $ks[count($ks) - 1];
        $depth = (int) config('ai.docs.rrf_depth');

        if ($largest > $depth) {
            throw RagEvalOptionException::kTooLarge($largest, $depth);
        }

        $rankings = [];
        $topSimilarity = [];

        foreach ($questions as $question) {
            $vector = $this->search->embedQuery($question->question);

            foreach ($modes as $mode) {
                $rankings[$mode->value][$question->id] = $this->search->search($question->question, $mode, $largest, $vector);
            }

            $vectorList = $rankings[DocsSearchMode::Vector->value][$question->id]
                ?? $this->search->search($question->question, DocsSearchMode::Vector, 1, $vector);

            $topSimilarity[$question->id] = $vectorList[0]->vectorSimilarity ?? -1.0;
        }

        $cells = [];

        foreach ($modes as $mode) {
            foreach ($ks as $k) {
                $cells[] = self::score($questions, $mode->value, $k, $rankings[$mode->value]);
            }
        }

        return new RagRetrievalResult($cells, $topSimilarity);
    }

    /**
     * Recall@k and MRR for one mode: a question hits at the rank of its first chunk from an expected document that carries an evidence phrase.
     *
     * @param  list<RagEvalQuestion>  $questions
     * @param  array<string, list<DocsMatch>>  $rankedByQuestion  best first, by question id
     */
    public static function score(array $questions, string $mode, int $k, array $rankedByQuestion): RagRecallCell
    {
        $total = 0;
        $hits = 0;
        $reciprocalRanks = 0.0;
        $missed = [];

        foreach ($questions as $question) {
            if (! $question->isOnTopic()) {
                continue;
            }

            $total++;
            $rank = self::firstHitRank($question, array_slice($rankedByQuestion[$question->id] ?? [], 0, $k));

            if ($rank === null) {
                $missed[] = $question->id;

                continue;
            }

            $hits++;
            $reciprocalRanks += 1 / $rank;
        }

        return new RagRecallCell($mode, $k, $total, $hits, $total === 0 ? 0.0 : $reciprocalRanks / $total, $missed);
    }

    /**
     * @param  list<DocsMatch>  $matches  best first
     * @return int|null 1-based rank of the first hit
     */
    public static function firstHitRank(RagEvalQuestion $question, array $matches): ?int
    {
        foreach ($matches as $index => $match) {
            if (self::isHit($question, $match)) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * A hit is a chunk of an expected document whose normalized body contains one of that document's evidence phrases.
     */
    public static function isHit(RagEvalQuestion $question, DocsMatch $match): bool
    {
        $body = null;

        foreach ($question->expected as $expectation) {
            if ($expectation->source !== $match->source || $expectation->path !== $match->path) {
                continue;
            }

            $body ??= RagEvalSet::normalizeEvidence($match->body);

            foreach ($expectation->evidence as $phrase) {
                if (str_contains($body, RagEvalSet::normalizeEvidence($phrase))) {
                    return true;
                }
            }
        }

        return false;
    }
}
