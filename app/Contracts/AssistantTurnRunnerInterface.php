<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\AssistantEvalQuestion;
use App\DTOs\AssistantEvalTurn;

interface AssistantTurnRunnerInterface
{
    /**
     * Asks the assistant one question on the given model and reports what happened; a model failure is a failed turn.
     */
    public function run(AssistantEvalQuestion $question, string $model): AssistantEvalTurn;
}
