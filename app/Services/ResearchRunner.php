<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\Ai\AiRunRecorder;
use App\Ai\ResearchAgent;
use App\Ai\ResearchCitations;
use App\Ai\ResearchNoteAgent;
use App\Ai\ResearchPlannerAgent;
use App\Ai\ResearchReportAgent;
use App\Contracts\ResearchNotesStore;
use App\Contracts\ResearchRunRepositoryInterface;
use App\DTOs\DocsMatch;
use App\DTOs\NewResearchRun;
use App\DTOs\NewResearchStep;
use App\DTOs\ResearchAgentCall;
use App\DTOs\ResearchRunOutcome;
use App\DTOs\ResearchRunRecord;
use App\DTOs\ResearchStepRecord;
use App\Enums\DocsSearchMode;
use App\Enums\ResearchRunStatus;
use App\Enums\ResearchStepPhase;
use App\Enums\ResearchStepStatus;
use App\Exceptions\ProviderNotConfiguredException;
use App\Exceptions\ProviderNotEnabledException;
use App\Exceptions\ResearchCapReachedException;
use App\Exceptions\ResearchDeadlineReachedException;
use App\Support\ResearchRunTally;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Runs the fixed phases of a research run: plan, then a search and a note for each sub-question, then the report. Every
 * phase is stored as a step, so the run can be shown again without a model call.
 */
final readonly class ResearchRunner
{
    public const MIN_TOPIC_LENGTH = 3;

    public const MAX_TOPIC_LENGTH = 300;

    public const MIN_SUB_QUESTIONS = 2;

    public const MIN_SUB_QUESTION_LENGTH = 5;

    public const MAX_SUB_QUESTION_LENGTH = 200;

    public const MAX_FACTS = 6;

    public const ERROR_STALE = 'stale';

    public const NOT_COVERED_REPORT = 'The documentation does not cover this topic: no source was relevant enough to report from.';

    public function __construct(
        private ResearchRunRepositoryInterface $runs,
        private DocsSearchService $search,
        private ResearchNotesStore $notes,
        private PromptInjectionGuard $guard,
        private AiProviders $providers,
    ) {}

    /**
     * Stores a run for the topic: `running` and ready for execute(), or `refused` when the topic matches the injection
     * guard, in which case no model is called.
     *
     * @throws InvalidArgumentException when the topic is not 3 to 300 characters.
     * @throws ProviderNotEnabledException when the provider override is not an enabled text provider.
     * @throws ProviderNotConfiguredException when the feature's cloud provider has no key.
     */
    public function start(string $topic, ?string $provider = null): ResearchRunRecord
    {
        $topic = trim($topic);
        $length = mb_strlen($topic);

        if ($length < self::MIN_TOPIC_LENGTH || $length > self::MAX_TOPIC_LENGTH) {
            throw new InvalidArgumentException('The topic must be '.self::MIN_TOPIC_LENGTH.' to '.self::MAX_TOPIC_LENGTH.' characters.');
        }

        $versions = $this->promptVersions();
        $pattern = $this->guard->match($topic);

        if ($pattern !== null) {
            $startedAt = hrtime(true);
            $choice = $this->providers->configured('research', $provider);
            $reason = 'input:'.pathinfo((string) config('ai.docs.answer.guard'), PATHINFO_FILENAME).':'.$pattern;

            app(AiRunRecorder::class)->recordOutcome('refused', class_basename(ResearchPlannerAgent::class), $choice->provider, $choice->model, $versions['planner'], $this->elapsedMs($startedAt), $reason);

            return $this->runs->create(new NewResearchRun($topic, $choice->provider, $choice->model, $versions, ResearchRunStatus::Refused, $reason));
        }

        $choice = $this->providers->forFeature('research', $provider);

        return $this->runs->create(new NewResearchRun($topic, $choice->provider, $choice->model, $versions));
    }

    /**
     * Starts a run and executes it in this process.
     *
     * @param  (Closure(ResearchStepRecord): void)|null  $onStep  called after each stored step
     */
    public function run(string $topic, ?string $provider = null, ?Closure $onStep = null): ResearchRunRecord
    {
        return $this->execute($this->start($topic, $provider)->id, $onStep);
    }

    /**
     * Executes a stored run that is still `running` and returns it as it ended. A run in any other status is returned as it is.
     *
     * @param  (Closure(ResearchStepRecord): void)|null  $onStep  called after each stored step
     */
    public function execute(string $runId, ?Closure $onStep = null): ResearchRunRecord
    {
        $run = $this->runs->find($runId) ?? throw new RuntimeException("No research run {$runId}.");

        if ($run->status !== ResearchRunStatus::Running) {
            return $run;
        }

        if ($run->isStale()) {
            $this->runs->fail($runId, self::ERROR_STALE);

            return $this->reload($run);
        }

        $tally = new ResearchRunTally;
        $tally->onStep = $onStep;
        $tally->deadline = now()->toImmutable()->addSeconds((int) config('ai.research.max_seconds'));
        $subQuestions = null;

        try {
            $outcome = $this->perform($run, $tally, $subQuestions);
        } catch (ResearchCapReachedException) {
            $outcome = $this->outcome($tally, ResearchRunStatus::Failed, subQuestions: $subQuestions, error: 'cap_reached');
        } catch (ResearchDeadlineReachedException) {
            $outcome = $this->outcome($tally, ResearchRunStatus::Failed, subQuestions: $subQuestions, error: 'deadline_reached');
        } catch (Throwable $e) {
            report($e);

            $outcome = $this->outcome($tally, ResearchRunStatus::Failed, subQuestions: $subQuestions, error: 'run_failed');
        }

        $this->runs->finish($runId, $outcome);

        return $this->reload($run);
    }

    /**
     * The run as stored now; the given record when it has gone.
     */
    private function reload(ResearchRunRecord $run): ResearchRunRecord
    {
        return $this->runs->find($run->id) ?? $run;
    }

    /**
     * @param  list<string>|null  $subQuestions  set once the plan is valid, so a failure later still stores it
     */
    private function perform(ResearchRunRecord $run, ResearchRunTally $tally, ?array &$subQuestions): ResearchRunOutcome
    {
        [$subQuestions, $planError] = $this->plan($run, $tally);

        if ($subQuestions === null) {
            return $this->outcome($tally, ResearchRunStatus::Failed, error: $planError);
        }

        $this->runs->setSubQuestions($run->id, $subQuestions);
        $this->guardCaps(count($subQuestions), $tally);

        /** @var array<int, array{source: string, path: string, heading: ?string}> $chunkInfo */
        $chunkInfo = [];
        /** @var array<int, array<string, mixed>> $localNotes */
        $localNotes = [];

        foreach ($subQuestions as $index => $subQuestion) {
            [$provided, $searchFailed] = $this->searchPhase($run, $tally, $index, $subQuestion, $chunkInfo);

            $note = $this->notePhase($run, $tally, $index, $subQuestion, $provided, $searchFailed);

            if ($note !== null) {
                $localNotes[$index] = $note;
                $this->keepNote($run->id, $index, $note);
            }
        }

        // "Not covered" is an answer only when every note was written: a note that failed, or whose search failed, says nothing.
        $hasFacts = array_filter($localNotes, fn (array $note): bool => $note['facts'] !== []) !== [];

        if (! $hasFacts && $tally->failedNotes > 0) {
            return $this->outcome($tally, ResearchRunStatus::Failed, subQuestions: $subQuestions, error: 'no_usable_notes');
        }

        return $this->reportPhase($run, $tally, $subQuestions, $localNotes, $chunkInfo);
    }

    /**
     * @return array{0: list<string>|null, 1: string|null} the valid sub-questions, or null and the error after a second invalid plan
     */
    private function plan(ResearchRunRecord $run, ResearchRunTally $tally): array
    {
        $error = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $call = $this->call(
                $this->agent(ResearchPlannerAgent::class, $run),
                'Topic: '.$run->topic,
                $tally,
                fn (ResearchAgentCall $call): bool => $this->validPlan(($call->structured() ?? [])['sub_questions'] ?? null) !== null,
            );
            $structured = $call->structured();
            $valid = $call->error === null && $structured !== null ? $this->validPlan($structured['sub_questions'] ?? null) : null;

            if ($valid !== null) {
                $this->store($run->id, $tally, new NewResearchStep(ResearchStepPhase::Plan, ['topic' => $run->topic], ['sub_questions' => $valid], ResearchStepStatus::Ok, $call->durationMs, aiRunId: $call->aiRunId));

                return [$valid, null];
            }

            $error = $call->error ?? 'invalid_plan';
            $status = $attempt === 1 ? ResearchStepStatus::Retried : ResearchStepStatus::Failed;
            $this->store($run->id, $tally, new NewResearchStep(ResearchStepPhase::Plan, ['topic' => $run->topic], $structured, $status, $call->durationMs, aiRunId: $call->aiRunId, error: $error));
        }

        return [null, $error];
    }

    /**
     * Trims, drops duplicates and sub-questions outside 5 to 200 characters, and keeps the first five.
     *
     * @return list<string>|null null when fewer than two valid sub-questions remain
     */
    private function validPlan(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $kept = [];

        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }

            $text = trim($item);
            $length = mb_strlen($text);

            if ($length >= self::MIN_SUB_QUESTION_LENGTH && $length <= self::MAX_SUB_QUESTION_LENGTH) {
                $kept[mb_strtolower($text)] ??= $text;
            }
        }

        $kept = array_slice(array_values($kept), 0, max(self::MIN_SUB_QUESTIONS, (int) config('ai.research.max_sub_questions')));

        return count($kept) >= self::MIN_SUB_QUESTIONS ? $kept : null;
    }

    /**
     * Ends the run before the notes when the remaining calls or steps cannot cover them and the report.
     */
    private function guardCaps(int $subQuestionCount, ResearchRunTally $tally): void
    {
        if ($tally->modelCalls + $subQuestionCount + 1 > (int) config('ai.research.max_model_calls')) {
            throw ResearchCapReachedException::for('model calls');
        }

        if ($tally->steps + 2 * $subQuestionCount + 1 > (int) config('ai.research.max_steps')) {
            throw ResearchCapReachedException::for('steps');
        }
    }

    /**
     * Hybrid search of the sub-question; no model call. Chunks under the docs relevance threshold are left out.
     *
     * @param  array<int, array{source: string, path: string, heading: ?string}>  $chunkInfo  filled with the chunks that may ground the note
     * @return array{0: list<DocsMatch>, 1: bool} the chunks the note may use, and whether the search failed
     */
    private function searchPhase(ResearchRunRecord $run, ResearchRunTally $tally, int $index, string $subQuestion, array &$chunkInfo): array
    {
        $startedAt = hrtime(true);

        try {
            $matches = $this->search->search($subQuestion, DocsSearchMode::Hybrid, max(1, (int) config('ai.research.chunks_per_note')));
        } catch (Throwable $e) {
            report($e);

            $this->store($run->id, $tally, new NewResearchStep(ResearchStepPhase::Search, ['query' => $subQuestion], null, ResearchStepStatus::Failed, $this->elapsedMs($startedAt), $index, error: 'search_failed'));

            return [[], true];
        }

        $threshold = (float) config('ai.docs.answer.min_similarity');
        $relevant = array_values(array_filter($matches, fn (DocsMatch $match): bool => $match->vectorSimilarity !== null && $match->vectorSimilarity >= $threshold));

        foreach ($relevant as $match) {
            $chunkInfo[$match->id] = ['source' => $match->source, 'path' => $match->path, 'heading' => $match->heading];
        }

        $chunks = [];

        foreach ($matches as $position => $match) {
            $chunks[] = [
                'id' => $match->id,
                'path' => $match->path,
                'heading' => $match->heading,
                'vector_similarity' => $match->vectorSimilarity === null ? null : round($match->vectorSimilarity, 6),
                'rank' => $position + 1,
            ];
        }

        $this->store($run->id, $tally, new NewResearchStep(ResearchStepPhase::Search, ['query' => $subQuestion], ['chunks' => $chunks], ResearchStepStatus::Ok, $this->elapsedMs($startedAt), $index));

        return [$relevant, false];
    }

    /**
     * The note of one sub-question, as kept in the working notes; null when its model call failed or its output was invalid.
     *
     * @param  list<DocsMatch>  $provided
     * @param  bool  $searchFailed  the search itself failed: the note is skipped as `search_failed`, and counts as a failed note
     * @return array<string, mixed>|null
     */
    private function notePhase(ResearchRunRecord $run, ResearchRunTally $tally, int $index, string $subQuestion, array $provided, bool $searchFailed): ?array
    {
        $providedIds = array_map(fn (DocsMatch $match): int => $match->id, $provided);
        $input = ['sub_question' => $subQuestion, 'chunk_ids' => $providedIds];

        if ($provided === []) {
            $output = ['answered' => false, 'reason' => $searchFailed ? 'search_failed' : 'no_sources', 'facts' => [], 'dropped_chunk_ids' => []];
            $tally->failedNotes += $searchFailed ? 1 : 0;
            $this->store($run->id, $tally, new NewResearchStep(ResearchStepPhase::Note, $input, $output, ResearchStepStatus::Skipped, 0, $index));

            return ['sub_question' => $subQuestion, ...$output];
        }

        $call = $this->call(
            $this->agent(ResearchNoteAgent::class, $run),
            $this->notePrompt($subQuestion, $provided),
            $tally,
            fn (ResearchAgentCall $call): bool => is_array(($call->structured() ?? [])['facts'] ?? null),
        );
        $structured = $call->structured();

        if ($call->error !== null || $structured === null || ! is_array($structured['facts'] ?? null)) {
            $tally->failedNotes++;
            $this->store($run->id, $tally, new NewResearchStep(ResearchStepPhase::Note, $input, $structured, ResearchStepStatus::Failed, $call->durationMs, $index, $call->aiRunId, $call->error ?? 'invalid_note'));

            return null;
        }

        [$facts, $dropped] = $this->facts($structured['facts'], $providedIds);
        $tally->droppedChunkIds += count($dropped);
        $output = ['facts' => $facts, 'answered' => $facts !== [], 'dropped_chunk_ids' => $dropped];

        $this->store($run->id, $tally, new NewResearchStep(ResearchStepPhase::Note, $input, $output, ResearchStepStatus::Ok, $call->durationMs, $index, $call->aiRunId));

        return ['sub_question' => $subQuestion, ...$output];
    }

    /**
     * Keeps the first six facts with text, drops every chunk id that was not given to the note, and drops a fact left with none.
     *
     * @param  array<mixed>  $rawFacts
     * @param  list<int>  $providedIds
     * @return array{0: list<array{text: string, chunk_ids: list<int>}>, 1: list<int>} the facts, and the chunk ids that were dropped
     */
    private function facts(array $rawFacts, array $providedIds): array
    {
        $facts = [];
        $dropped = [];

        foreach (array_slice(array_values($rawFacts), 0, self::MAX_FACTS) as $raw) {
            $text = is_array($raw) && is_string($raw['text'] ?? null) ? trim($raw['text']) : '';
            $ids = is_array($raw) && is_array($raw['chunk_ids'] ?? null) ? $raw['chunk_ids'] : [];
            $valid = [];

            foreach ($ids as $id) {
                if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                    continue;
                }

                if (in_array((int) $id, $providedIds, true)) {
                    $valid[(int) $id] = (int) $id;
                } else {
                    $dropped[(int) $id] = (int) $id;
                }
            }

            if ($text !== '' && $valid !== []) {
                $facts[] = ['text' => $text, 'chunk_ids' => array_values($valid)];
            }
        }

        return [$facts, array_values($dropped)];
    }

    /**
     * The sub-question and its chunks, each chunk cut to the configured length and wrapped as data.
     *
     * @param  list<DocsMatch>  $provided
     */
    private function notePrompt(string $subQuestion, array $provided): string
    {
        $limit = max(1, (int) config('ai.research.chunk_characters'));
        $chunks = array_map(function (DocsMatch $match) use ($limit): string {
            $body = str_ireplace('</chunk', '&lt;/chunk', mb_substr($match->body, 0, $limit));

            return sprintf(
                "<chunk id=\"%d\" path=\"%s\" heading=\"%s\">\n%s\n</chunk>",
                $match->id,
                htmlspecialchars($match->path, ENT_QUOTES),
                htmlspecialchars((string) $match->heading, ENT_QUOTES),
                $body,
            );
        }, $provided);

        return "Sub-question: {$subQuestion}\n\n".implode("\n\n", $chunks);
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function keepNote(string $runId, int $index, array $note): void
    {
        try {
            $this->notes->put($runId, $index, $note);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  list<string>  $subQuestions
     * @param  array<int, array<string, mixed>>  $localNotes  the notes of this execution, the fallback when the working notes are gone
     * @param  array<int, array{source: string, path: string, heading: ?string}>  $chunkInfo
     */
    private function reportPhase(ResearchRunRecord $run, ResearchRunTally $tally, array $subQuestions, array $localNotes, array $chunkInfo): ResearchRunOutcome
    {
        [$notes, $notesSource] = $this->workingNotes($run->id, $localNotes);

        $numbers = [];
        $sources = [];
        $lines = [];
        $factCount = 0;

        foreach ($notes as $note) {
            $noteLines = [];

            foreach ((array) ($note['facts'] ?? []) as $fact) {
                $markers = '';

                foreach ($fact['chunk_ids'] as $chunkId) {
                    if (! isset($numbers[$chunkId])) {
                        $numbers[$chunkId] = count($numbers) + 1;
                        $sources[] = ['n' => $numbers[$chunkId], 'chunk_id' => $chunkId, ...($chunkInfo[$chunkId] ?? ['source' => '', 'path' => '', 'heading' => null])];
                    }

                    $markers .= '['.$numbers[$chunkId].']';
                }

                $noteLines[] = "- {$fact['text']} {$markers}";
                $factCount++;
            }

            if ($noteLines !== []) {
                $lines[] = "## {$note['sub_question']}\n".implode("\n", $noteLines);
            }
        }

        $input = ['fact_count' => $factCount, 'source_count' => count($sources), 'notes_source' => $notesSource];

        if ($factCount === 0) {
            $this->store($run->id, $tally, new NewResearchStep(ResearchStepPhase::Report, $input, ['report' => self::NOT_COVERED_REPORT, 'unknown_citations' => 0, 'reason' => 'no_facts'], ResearchStepStatus::Ok, 0));

            return $this->outcome($tally, ResearchRunStatus::Done, report: self::NOT_COVERED_REPORT, sources: [], subQuestions: $subQuestions);
        }

        $sourceLines = array_map(fn (array $source): string => "[{$source['n']}] {$source['path']}".($source['heading'] === null ? '' : " — {$source['heading']}"), $sources);
        $prompt = "Topic: {$run->topic}\n\nNotes:\n".implode("\n\n", $lines)."\n\nSources:\n".implode("\n", $sourceLines);

        $call = $this->call(
            $this->agent(ResearchReportAgent::class, $run),
            $prompt,
            $tally,
            fn (ResearchAgentCall $call): bool => trim((string) $call->text()) !== '',
        );
        $text = trim((string) $call->text());

        if ($call->error !== null || $text === '') {
            $error = $call->error ?? 'empty_report';
            $this->store($run->id, $tally, new NewResearchStep(ResearchStepPhase::Report, $input, null, ResearchStepStatus::Failed, $call->durationMs, aiRunId: $call->aiRunId, error: $error));

            return $this->outcome($tally, ResearchRunStatus::Failed, sources: $sources, subQuestions: $subQuestions, error: $error);
        }

        $cited = ResearchCitations::strip($text, count($sources));
        $tally->unknownCitations += count($cited['unknown']);

        $this->store($run->id, $tally, new NewResearchStep(ResearchStepPhase::Report, $input, ['report' => $cited['text'], 'unknown_citations' => count($cited['unknown'])], ResearchStepStatus::Ok, $call->durationMs, aiRunId: $call->aiRunId));

        return $this->outcome($tally, ResearchRunStatus::Done, report: $cited['text'], sources: $sources, subQuestions: $subQuestions);
    }

    /**
     * The notes the report reads: the Redis hash, or this execution's own notes when the hash is gone or incomplete.
     *
     * @param  array<int, array<string, mixed>>  $localNotes
     * @return array{0: array<int, array<string, mixed>>, 1: string} the notes by sub-question index, and where they came from
     */
    private function workingNotes(string $runId, array $localNotes): array
    {
        try {
            $stored = $this->notes->all($runId);
        } catch (Throwable $e) {
            report($e);
            $stored = [];
        }

        if (array_keys($stored) === array_keys($localNotes)) {
            return [$stored, 'redis'];
        }

        ksort($localNotes);

        return [$localNotes, 'steps'];
    }

    /**
     * Prompts the agent and logs the call in `ai_runs`; a failure is returned, not thrown, so the phase can store it. The
     * model's own error text stays out of the run: it is reported, and the run keeps the code `model_call_failed`.
     *
     * @param  Closure(ResearchAgentCall): bool  $isValid  whether the output is usable, logged as the row's validation result
     *
     * @throws ResearchCapReachedException when the run already made its allowed model calls.
     * @throws ResearchDeadlineReachedException when the call could not finish inside the run's time budget.
     */
    private function call(ResearchAgent $agent, string $prompt, ResearchRunTally $tally, Closure $isValid): ResearchAgentCall
    {
        if ($tally->modelCalls >= (int) config('ai.research.max_model_calls')) {
            throw ResearchCapReachedException::for('model calls');
        }

        $this->guardDeadline($agent, $tally);

        $tally->modelCalls++;
        $recorder = app(AiRunRecorder::class);
        $startedAt = hrtime(true);

        try {
            $response = $agent->prompt($prompt);
        } catch (Throwable $e) {
            report($e);

            $durationMs = $this->elapsedMs($startedAt);
            $invocationId = $recorder->recordRun('failed', class_basename($agent), $agent->providerName(), $agent->modelName(), $agent->promptVersion(), $durationMs, null, exception: $e);

            return new ResearchAgentCall(null, $this->runs->aiRunIdOf($invocationId), $durationMs, 'model_call_failed');
        }

        $durationMs = $this->elapsedMs($startedAt);
        $tally->tokensIn += $response->usage->inputTokens;
        $tally->tokensOut += $response->usage->outputTokens;

        $valid = $isValid(new ResearchAgentCall($response, null, $durationMs));

        $invocationId = $recorder->recordRun(
            'completed',
            class_basename($agent),
            $agent->providerName(),
            $agent->modelName(),
            $agent->promptVersion(),
            $durationMs,
            null,
            tokensIn: $response->usage->inputTokens,
            tokensOut: $response->usage->outputTokens,
            invocationId: $response->invocationId,
            validationFailed: ! $valid,
        );

        return new ResearchAgentCall($response, $this->runs->aiRunIdOf($invocationId), $durationMs);
    }

    /**
     * A call may start only when it can end, at its provider timeout, before the run's deadline. The first call always runs.
     *
     * @throws ResearchDeadlineReachedException
     */
    private function guardDeadline(ResearchAgent $agent, ResearchRunTally $tally): void
    {
        if ($tally->deadline === null || $tally->modelCalls === 0) {
            return;
        }

        if (now()->getTimestamp() + $agent->timeout() > $tally->deadline->getTimestamp()) {
            throw ResearchDeadlineReachedException::reached();
        }
    }

    /**
     * @template T of ResearchAgent
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function agent(string $class, ResearchRunRecord $run): ResearchAgent
    {
        return (new $class)->usingProvider($run->provider)->usingModel($run->model);
    }

    /**
     * @throws ResearchCapReachedException when the run already stored its allowed steps.
     */
    private function store(string $runId, ResearchRunTally $tally, NewResearchStep $step): void
    {
        if ($tally->steps >= (int) config('ai.research.max_steps')) {
            throw ResearchCapReachedException::for('steps');
        }

        $stored = $this->runs->addStep($runId, $step);
        $tally->steps++;

        if ($tally->onStep !== null) {
            ($tally->onStep)($stored);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>|null  $subQuestions
     */
    private function outcome(ResearchRunTally $tally, ResearchRunStatus $status, ?string $report = null, array $sources = [], ?array $subQuestions = null, ?string $error = null): ResearchRunOutcome
    {
        return new ResearchRunOutcome(
            status: $status,
            report: $report,
            sources: $sources,
            subQuestions: $subQuestions,
            error: $error,
            modelCalls: $tally->modelCalls,
            failedNotes: $tally->failedNotes,
            droppedChunkIds: $tally->droppedChunkIds,
            unknownCitations: $tally->unknownCitations,
            tokensIn: $tally->tokensIn,
            tokensOut: $tally->tokensOut,
        );
    }

    /**
     * @return array{planner: string, note: string, report: string}
     */
    private function promptVersions(): array
    {
        return [
            'planner' => (new ResearchPlannerAgent)->promptVersion(),
            'note' => (new ResearchNoteAgent)->promptVersion(),
            'report' => (new ResearchReportAgent)->promptVersion(),
        ];
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
