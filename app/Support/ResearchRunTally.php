<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\ResearchStepRecord;
use Carbon\CarbonImmutable;
use Closure;

/**
 * What one run has used so far: the numbers the caps are checked against and the counts stored on the run.
 */
final class ResearchRunTally
{
    public int $modelCalls = 0;

    public int $steps = 0;

    public int $failedNotes = 0;

    public int $droppedChunkIds = 0;

    public int $unknownCitations = 0;

    public int $tokensIn = 0;

    public int $tokensOut = 0;

    /** The time the run must be over by; a model call starts only when it can finish before it. */
    public ?CarbonImmutable $deadline = null;

    /**
     * @var (Closure(ResearchStepRecord): void)|null called after each stored step
     */
    public ?Closure $onStep = null;
}
