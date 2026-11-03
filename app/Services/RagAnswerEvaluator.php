<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RagEvalRepositoryInterface;
use App\DTOs\DocsAnswerResult;
use App\DTOs\RagAnswerRow;
use App\DTOs\RagEvalQuestion;
use App\DTOs\RagModelReport;
use App\Enums\DocsAnswerOutcome;
use App\Exceptions\DocsAnswerUnavailableException;
use App\Exceptions\RagEvalModelException;
use App\Exceptions\RagGraderUnavailableException;

/**
 * Runs every eval question through the docs chatbot with each model and repeat, and scores the outcomes.
 */
final readonly class RagAnswerEvaluator
{
    public function __construct(
        private DocsAnswerService $answers,
        private RagAnswerGrader $grader,
        private RagEvalRepositoryInterface $repository,
    ) {}

    /**
     * An answered result is graded by the grader model. A decline (no_context or a refusal) is scored without a grader:
     * right for an off-topic question, a false "don't know" or false refusal for an on-topic one.
     *
     * @param  list<RagEvalQuestion>  $questions
     * @param  list<string>  $models
     * @param  (callable(string, int): void)|null  $progress  called with the model and the run number before each run
     * @return list<RagModelReport>
     *
     * @throws DocsAnswerUnavailableException when a question cannot be embedded.
     * @throws RagEvalModelException when every answer of a model's first run fails.
     * @throws RagGraderUnavailableException when the first graded answer fails with a provider error on both attempts.
     */
    public function evaluate(array $questions, array $models, string $graderModel, int $repeat, ?callable $progress = null): array
    {
        $reports = [];
        $graderVerified = false;

        foreach ($models as $model) {
            $runs = [];
            $invocationIds = [];

            for ($run = 1; $run <= $repeat; $run++) {
                if ($progress !== null) {
                    $progress($model, $run);
                }

                $rows = [];

                foreach ($questions as $question) {
                    $result = $this->answers->answer($question->question, $model);

                    if ($this->reachedModel($result)) {
                        $invocationIds[] = $result->invocationId;
                    }

                    $row = $this->score($question, $result, $graderModel);

                    if ($row->category === RagAnswerRow::GRADED) {
                        $graderVerified = true;
                    } elseif (! $graderVerified && $row->category === RagAnswerRow::UNGRADED && $row->failure === 'provider_error') {
                        throw RagGraderUnavailableException::forModel($graderModel);
                    }

                    $rows[] = $row;
                }

                if ($run === 1) {
                    $this->assertModelReachable($model, $rows);
                }

                $runs[] = $rows;
            }

            $timings = $this->repository->timings($invocationIds);

            $reports[] = new RagModelReport(
                $model,
                $runs,
                self::median(array_map(fn ($timing): int => $timing->latencyMs, $timings)),
                self::median(array_values(array_filter(array_map(fn ($timing): ?int => $timing->ttftMs, $timings), fn (?int $value): bool => $value !== null))),
            );
        }

        return $reports;
    }

    /**
     * Only runs that called the answer model have a latency and TTFT worth a median; a refused input or no_context
     * row is a guard or a search and would pull the latency median down while the TTFT median ignores it.
     */
    private function reachedModel(DocsAnswerResult $result): bool
    {
        return in_array($result->outcome, [DocsAnswerOutcome::Answered, DocsAnswerOutcome::RefusedOutput, DocsAnswerOutcome::Failed], true);
    }

    /**
     * A model that failed every call it was given in the first run is unavailable, not flaky: the run stops.
     *
     * @param  list<RagAnswerRow>  $rows
     *
     * @throws RagEvalModelException
     */
    private function assertModelReachable(string $model, array $rows): void
    {
        $called = array_filter($rows, fn (RagAnswerRow $row): bool => $row->category === RagAnswerRow::FAILED || $row->outcome === 'completed');
        $failed = array_filter($called, fn (RagAnswerRow $row): bool => $row->category === RagAnswerRow::FAILED);

        if ($failed !== [] && count($failed) === count($called)) {
            throw RagEvalModelException::answerModelFailed($model);
        }
    }

    private function score(RagEvalQuestion $question, DocsAnswerResult $result, string $graderModel): RagAnswerRow
    {
        $onTopic = $question->isOnTopic();
        $outcome = $result->outcome->specName();
        $stage = in_array($result->outcome, [DocsAnswerOutcome::RefusedInput, DocsAnswerOutcome::RefusedOutput], true) ? $result->outcome->value : null;
        $cited = $result->citedIds !== [];

        if ($result->outcome === DocsAnswerOutcome::Failed) {
            return new RagAnswerRow($question->id, $onTopic, $outcome, RagAnswerRow::FAILED, null, null, false, $result->invocationId, null, null, null);
        }

        if ($result->outcome === DocsAnswerOutcome::Answered) {
            $grade = $this->grader->grade($question, $result, $graderModel);

            return new RagAnswerRow(
                $question->id, $onTopic, $outcome,
                $grade->isGraded() ? RagAnswerRow::GRADED : RagAnswerRow::UNGRADED,
                $grade->correctness, $grade->groundedness, $cited, $result->invocationId, $grade->invocationId, $grade->reason, $grade->failure,
            );
        }

        $category = match (true) {
            ! $onTopic => RagAnswerRow::DECLINED_CORRECTLY,
            $result->outcome === DocsAnswerOutcome::NoContext => RagAnswerRow::FALSE_DONT_KNOW,
            default => RagAnswerRow::FALSE_REFUSAL,
        };

        return new RagAnswerRow($question->id, $onTopic, $outcome, $category, $onTopic ? 0 : 2, null, false, $result->invocationId, null, null, null, $stage);
    }

    /**
     * @param  list<int>  $values
     */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
