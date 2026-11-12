<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Tools\SearchListings;
use App\DTOs\AssistantEvalCall;
use App\DTOs\AssistantEvalQuestion;
use App\DTOs\AssistantEvalTurn;

/**
 * The metrics of one model over the assistant questions. A rate whose denominator is zero is null.
 */
final readonly class AssistantEvalScorer
{
    /** Only the link form the prompt requires counts as a listing id; "#1 pick" or "id 3" are ordinary numbers. */
    private const ID_PATTERN = '/\/listings\/(\d+)/';

    private const LINK_PATTERN = '/\/listings\/(\d+)/';

    /**
     * Spellings of a city that count as the name the catalogue uses.
     */
    private const CITY_ALIASES = [
        'kiev' => 'kyiv',
        'praha' => 'prague',
        'wien' => 'vienna',
        'münchen' => 'munich',
        'munchen' => 'munich',
        'köln' => 'cologne',
    ];

    private const MENTION_PATTERN = '/\blisting\s+#?(\d+)/i';

    /**
     * @param  list<AssistantEvalQuestion>  $questions
     * @param  list<AssistantEvalTurn>  $turns  one per question, in the same order
     * @return array<string, mixed>
     */
    public function score(string $model, array $questions, array $turns): array
    {
        $called = 0;
        $calledExpected = 0;
        $expected = 0;
        $firstTry = 0;
        $withInvalid = 0;
        $repaired = 0;
        $filterHits = 0;
        $steps = [];
        $overLabel = 0;
        $answered = 0;
        $hallucinated = 0;
        $unlinked = 0;
        $unsupportedAsked = 0;
        $unsupportedMentioned = 0;
        $latencies = [];

        foreach ($questions as $index => $question) {
            $turn = $turns[$index];
            $count = count($turn->calls);
            $steps[] = $count;
            $overLabel += (int) ($count > $question->maxStepsOk);
            $expected += (int) $question->expectsTool;
            $called += (int) ($count > 0);
            $calledExpected += (int) ($count > 0 && $question->expectsTool);

            if ($count > 0) {
                $firstTry += (int) ($turn->calls[0]->outcome === AssistantEvalCall::OK);
            }

            $invalidAt = $this->firstIndex($turn->calls, AssistantEvalCall::INVALID);

            if ($invalidAt !== null) {
                $withInvalid++;
                $repaired += (int) ($this->firstIndex(array_slice($turn->calls, $invalidAt + 1), AssistantEvalCall::OK) !== null);
            }

            if ($question->expectsTool) {
                $valid = $this->firstIndex($turn->calls, AssistantEvalCall::OK);
                $filterHits += (int) ($valid !== null && $this->matches($question->expectedFilters, $turn->calls[$valid]->arguments));
            }

            if ($turn->status === AssistantEvalTurn::COMPLETED) {
                $answered++;
                $hallucinated += (int) ($this->hallucinatedIds($turn) !== []);
                $unlinked += (int) ($this->unlinkedMentions($turn) !== []);
                $latencies[] = $turn->latencyMs;

                if ($question->unsupported !== []) {
                    $unsupportedAsked++;
                    $unsupportedMentioned += (int) $this->mentionsUnsupported($question, $turn);
                }
            }
        }

        $total = count($questions);
        $max = $steps === [] ? 0 : max($steps);

        return [
            'model' => $model,
            'questions' => $total,
            'failed' => $total - $answered,
            'tool_call_precision' => ['count' => $calledExpected, 'of' => $called, 'rate' => $this->rate($calledExpected, $called)],
            'tool_call_recall' => ['count' => $calledExpected, 'of' => $expected, 'rate' => $this->rate($calledExpected, $expected)],
            'argument_validity_first_try' => ['count' => $firstTry, 'of' => $called, 'rate' => $this->rate($firstTry, $called)],
            'repair_success' => ['count' => $repaired, 'of' => $withInvalid, 'rate' => $this->rate($repaired, $withInvalid)],
            'filter_accuracy' => ['count' => $filterHits, 'of' => $expected, 'rate' => $this->rate($filterHits, $expected)],
            'steps' => [
                'mean' => $total === 0 ? null : round(array_sum($steps) / $total, 2),
                'max' => $max,
                'limit' => SearchListings::MAX_CALLS,
                'within_limit' => $max <= SearchListings::MAX_CALLS,
                'over_label' => $overLabel,
            ],
            'answers_citing_only_returned_ids' => [
                'count' => $answered - $hallucinated,
                'of' => $answered,
                'rate' => $this->rate($answered - $hallucinated, $answered),
                'hallucinated' => $hallucinated,
                'hallucinated_rate' => $this->rate($hallucinated, $answered),
            ],
            'unlinked_mentions' => ['count' => $unlinked, 'of' => $answered, 'rate' => $this->rate($unlinked, $answered)],
            'unsupported_mentioned' => ['count' => $unsupportedMentioned, 'of' => $unsupportedAsked, 'rate' => $this->rate($unsupportedMentioned, $unsupportedAsked)],
            'latency_ms' => [
                'count' => count($latencies),
                'p50' => NlEvalScorer::percentile($latencies, 50),
                'p95' => NlEvalScorer::percentile($latencies, 95),
            ],
        ];
    }

    /**
     * The listing ids the answer names ("#12", "/listings/12", "id 12", "listing 12") that no tool call of the turn returned.
     *
     * @return list<int>
     */
    public function hallucinatedIds(AssistantEvalTurn $turn): array
    {
        preg_match_all(self::ID_PATTERN, $turn->answer, $found);
        $returned = array_merge([], ...array_map(fn (AssistantEvalCall $call): array => $call->returnedIds, $turn->calls));

        return array_values(array_unique(array_diff(array_map(intval(...), $found[1]), $returned)));
    }

    /**
     * The listing ids the answer names as "listing N" without a /listings/N link anywhere in the answer.
     *
     * @return list<int>
     */
    public function unlinkedMentions(AssistantEvalTurn $turn): array
    {
        preg_match_all(self::MENTION_PATTERN, $turn->answer, $mentioned);
        preg_match_all(self::LINK_PATTERN, $turn->answer, $linked);

        return array_values(array_unique(array_diff(array_map(intval(...), $mentioned[1]), array_map(intval(...), $linked[1]))));
    }

    /**
     * Whether every expected field is in the arguments with the same value: strings trimmed and lowercased.
     *
     * @param  array<string, string|int>  $expected
     * @param  array<string, mixed>  $arguments
     */
    public function matches(array $expected, array $arguments): bool
    {
        foreach ($expected as $field => $value) {
            if ($this->normalize($value) !== $this->normalize($arguments[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the answer names at least one of the words the question labels as unsupported.
     */
    public function mentionsUnsupported(AssistantEvalQuestion $question, AssistantEvalTurn $turn): bool
    {
        $answer = mb_strtolower($turn->answer);

        // Whole words only: "red" must not match "offered", nor "near" match "nearly".
        foreach ($question->unsupported as $word) {
            if (preg_match('/\b'.preg_quote(mb_strtolower($word), '/').'\b/u', $answer) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<AssistantEvalCall>  $calls
     */
    private function firstIndex(array $calls, string $outcome): ?int
    {
        foreach ($calls as $index => $call) {
            if ($call->outcome === $outcome) {
                return $index;
            }
        }

        return null;
    }

    private function normalize(mixed $value): string|int|null
    {
        if (! is_string($value)) {
            return is_int($value) ? $value : null;
        }

        $text = mb_strtolower(trim($value));

        return self::CITY_ALIASES[$text] ?? $text;
    }

    private function rate(int $count, int $of): ?float
    {
        return $of === 0 ? null : round($count / $of, 3);
    }
}
