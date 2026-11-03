<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class RagEvalQuestion
{
    public const ON_TOPIC = 'on_topic';

    public const OFF_TOPIC = 'off_topic';

    /**
     * @param  list<RagEvalExpectation>  $expected
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $question,
        public string $answer,
        public array $expected,
        public array $tags,
        public int $line,
    ) {}

    public function isOnTopic(): bool
    {
        return $this->type === self::ON_TOPIC;
    }
}
