<?php

declare(strict_types=1);

namespace App\Enums;

enum DocsAnswerOutcome: string
{
    case Answered = 'answered';
    case RefusedInput = 'refused_input';
    case RefusedOutput = 'refused_output';
    case NoContext = 'no_context';
    case Failed = 'failed';

    /**
     * The outcome name the eval spec uses: completed, no_context, refused or failed.
     */
    public function specName(): string
    {
        return match ($this) {
            self::Answered => 'completed',
            self::RefusedInput, self::RefusedOutput => 'refused',
            self::NoContext => 'no_context',
            self::Failed => 'failed',
        };
    }
}
