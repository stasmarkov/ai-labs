<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * What one question scored in one answer run.
 */
final readonly class RagAnswerRow
{
    public const GRADED = 'graded';

    public const DECLINED_CORRECTLY = 'declined_correctly';

    public const FALSE_DONT_KNOW = 'false_dont_know';

    public const FALSE_REFUSAL = 'false_refusal';

    public const UNGRADED = 'ungraded';

    public const FAILED = 'failed';

    /**
     * @param  string  $outcome  the spec outcome: completed, no_context, refused or failed
     * @param  string|null  $stage  the DocsAnswerOutcome value of a refusal, such as refused_input
     * @param  string  $category  one of the category constants
     * @param  string|null  $failure  the grader failure class when ungraded
     */
    public function __construct(
        public string $questionId,
        public bool $onTopic,
        public string $outcome,
        public string $category,
        public ?int $correctness,
        public ?int $groundedness,
        public bool $cited,
        public string $invocationId,
        public ?string $graderInvocationId,
        public ?string $reason,
        public ?string $failure,
        public ?string $stage = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->questionId,
            'type' => $this->onTopic ? RagEvalQuestion::ON_TOPIC : RagEvalQuestion::OFF_TOPIC,
            'outcome' => $this->outcome,
            'category' => $this->category,
            'correctness' => $this->correctness,
            'groundedness' => $this->groundedness,
            'cited' => $this->cited,
            'invocation_id' => $this->invocationId,
            'grader_invocation_id' => $this->graderInvocationId,
            'reason' => $this->reason,
            'failure' => $this->failure,
            'stage' => $this->stage,
        ];
    }
}
