<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The five guide formats of the format trial, in the order of the compare page. The value is the URL segment and the
 * name of the format file; the schema says which list a file holds and the fields each item needs.
 */
enum GuideFormatType: string
{
    case BuildAlong = 'build-along';
    case Trace = 'trace';
    case Quiz = 'quiz';
    case Debug = 'debug';
    case Tour = 'tour';

    /**
     * The list in the format file that holds the items.
     */
    public function itemsField(): string
    {
        return match ($this) {
            self::BuildAlong => 'steps',
            self::Trace => 'stages',
            self::Quiz => 'questions',
            self::Debug => 'scenarios',
            self::Tour => 'stops',
        };
    }

    /**
     * The fields each item needs on top of id, title, outcomes and snippet, with their type.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::BuildAlong => ['goal' => 'string', 'command' => 'string', 'expected' => 'string'],
            self::Trace => ['what' => 'string', 'sample' => 'string'],
            self::Quiz => ['question' => 'string', 'options' => 'options', 'correct' => 'index:options', 'explanation' => 'string'],
            self::Debug => ['symptom' => 'string', 'clues' => 'clues', 'causes' => 'causes', 'correct' => 'index:causes', 'fix' => 'string', 'test' => 'string'],
            self::Tour => ['notes' => 'notes'],
        };
    }

    /**
     * Whether an item may leave out its snippet: a debug scenario can be told without code.
     */
    public function snippetIsOptional(): bool
    {
        return $this === self::Debug;
    }
}
