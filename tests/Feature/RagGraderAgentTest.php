<?php

declare(strict_types=1);

use App\Ai\RagGraderAgent;
use App\DTOs\DocsAnswerResult;
use App\DTOs\DocsMatch;
use App\DTOs\RagEvalExpectation;
use App\DTOs\RagEvalQuestion;
use App\DTOs\RagGrade;
use App\Enums\DocsAnswerOutcome;
use App\Models\AiRun;
use App\Services\RagAnswerGrader;

function graderQuestion(): RagEvalQuestion
{
    return new RagEvalQuestion('q-01', RagEvalQuestion::ON_TOPIC, 'how does rate limiting work', 'Limiters cap requests per minute.', [new RagEvalExpectation('laravel', 'rate-limiting.md', ['per minute'])], [], 1);
}

function graderResult(): DocsAnswerResult
{
    $source = new DocsMatch(7, 'laravel', 'rate-limiting.md', 'Limiters', 'Limiters cap requests per minute <for each key>.', 0.9, 1, 0.9, null, null);

    return new DocsAnswerResult(DocsAnswerOutcome::Answered, 'Limiters cap requests [1].', [$source], [7], 'answer-run');
}

it('AC-6 returns the grade of a valid reply and logs the call', function (): void {
    RagGraderAgent::fake([['correctness' => 2, 'groundedness' => 1, 'reason' => 'Matches the reference.']]);

    $grade = app(RagAnswerGrader::class)->grade(graderQuestion(), graderResult(), 'gemma-test');

    expect($grade->isGraded())->toBeTrue()
        ->and($grade->correctness)->toBe(2)
        ->and($grade->groundedness)->toBe(1)
        ->and($grade->reason)->toBe('Matches the reference.')
        ->and($grade->attempts)->toBe(1);

    RagGraderAgent::assertPromptedTimes(1);
    RagGraderAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, '<reference_answer>Limiters cap requests per minute.</reference_answer>')
        && str_contains($prompt->prompt, '<chatbot_answer>Limiters cap requests [1].</chatbot_answer>')
        && str_contains($prompt->prompt, '&lt;for each key&gt;')
        && $prompt->model === 'gemma-test');

    $run = AiRun::where('agent', 'RagGraderAgent')->sole();
    expect($run->invocation_id)->toBe($grade->invocationId)
        ->and($run->status)->toBe('completed')
        ->and($run->model)->toBe('gemma-test')
        ->and($run->prompt_version)->toBe('rag-grader@v2')
        ->and($run->retrieved_chunk_ids)->toBe([7]);
});

it('AC-6 cuts a reason longer than 300 characters without failing', function (): void {
    RagGraderAgent::fake([['correctness' => 0, 'groundedness' => 0, 'reason' => str_repeat('é', 450)]]);

    $grade = app(RagAnswerGrader::class)->grade(graderQuestion(), graderResult(), 'gemma-test');

    expect($grade->isGraded())->toBeTrue()
        ->and(mb_strlen((string) $grade->reason))->toBe(300);
});

it('AC-6 retries once after a bad reply and takes the valid one', function (): void {
    RagGraderAgent::fake([
        ['correctness' => 3, 'groundedness' => 1, 'reason' => 'out of range'],
        ['correctness' => 1, 'groundedness' => 2, 'reason' => 'Partly right.'],
    ]);

    $grade = app(RagAnswerGrader::class)->grade(graderQuestion(), graderResult(), 'gemma-test');

    expect($grade->isGraded())->toBeTrue()
        ->and($grade->correctness)->toBe(1)
        ->and($grade->attempts)->toBe(2);

    RagGraderAgent::assertPromptedTimes(2);
    expect(AiRun::where('agent', 'RagGraderAgent')->orderBy('id')->pluck('status')->all())->toBe(['failed', 'completed']);
});

it('AC-6 leaves the answer ungraded after two schema mismatches', function (): void {
    RagGraderAgent::fake([
        ['correctness' => 'good', 'groundedness' => 1, 'reason' => 'x'],
        ['correctness' => 1, 'reason' => 'groundedness missing'],
    ]);

    $grade = app(RagAnswerGrader::class)->grade(graderQuestion(), graderResult(), 'gemma-test');

    expect($grade->isGraded())->toBeFalse()
        ->and($grade->failure)->toBe(RagGrade::SCHEMA_MISMATCH)
        ->and($grade->correctness)->toBeNull()
        ->and($grade->attempts)->toBe(2);

    $runs = AiRun::where('agent', 'RagGraderAgent')->orderBy('id')->get();
    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('status')->all())->toBe(['failed', 'failed'])
        ->and($runs->last()->invocation_id)->toBe($grade->invocationId)
        ->and($runs->last()->error_message)->toContain('schema_mismatch');
});

it('AC-6 names a reply that is not JSON invalid_json', function (): void {
    RagGraderAgent::fake([[], []]);

    $grade = app(RagAnswerGrader::class)->grade(graderQuestion(), graderResult(), 'gemma-test');

    expect($grade->failure)->toBe(RagGrade::INVALID_JSON);
});

it('AC-6 reports a provider error after the retry as ungraded', function (): void {
    RagGraderAgent::fake([
        fn () => throw new RuntimeException('connection refused'),
        fn () => throw new RuntimeException('connection refused'),
    ]);

    $grade = app(RagAnswerGrader::class)->grade(graderQuestion(), graderResult(), 'gemma-test');

    expect($grade->isGraded())->toBeFalse()
        ->and($grade->failure)->toBe(RagGrade::PROVIDER_ERROR);
    expect(AiRun::where('agent', 'RagGraderAgent')->where('error_class', RuntimeException::class)->count())->toBe(2);
});

it('AC-6 tells the grader the expected behaviour: answer for an on-topic question, decline for an off-topic one', function (): void {
    $onTopic = graderQuestion();
    $offTopic = new RagEvalQuestion('off-01', RagEvalQuestion::OFF_TOPIC, 'best pasta recipe', "I don't know.", [], [], 2);
    $agent = new RagGraderAgent;

    expect($agent->promptFor($onTopic, 'Limiters cap requests [1].', []))
        ->toContain('<question_type>on_topic</question_type>')
        ->toContain('<expected_behaviour>answer</expected_behaviour>')
        ->and($agent->promptFor($offTopic, 'Carbonara.', []))
        ->toContain('<question_type>off_topic</question_type>')
        ->toContain('<expected_behaviour>decline</expected_behaviour>');
});

it('AC-6 keeps the v1 grader prompt for the committed results and defaults to v2, whose rules name both behaviours', function (): void {
    expect(file_exists(resource_path('prompts/rag-grader.v1.md')))->toBeTrue()
        ->and(config('ai.prompts.rag_grader'))->toBe('rag-grader.v2')
        ->and((new RagGraderAgent)->instructions())
        ->toContain('<expected_behaviour>')
        ->toContain('is answer')
        ->toContain('is decline')
        ->toContain('There is no 1 for a decline question.');
});
