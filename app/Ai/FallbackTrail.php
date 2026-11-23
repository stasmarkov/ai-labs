<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * What a non-streamed call learned about its fallback: the model it tried after the main one failed and why. Empty when
 * the main model answered or no fallback applied.
 */
final class FallbackTrail
{
    public ?string $model = null;

    public ?string $reason = null;

    public function record(string $model, string $reason): void
    {
        $this->model = $model;
        $this->reason = $reason;
    }

    public function attempted(): bool
    {
        return $this->model !== null;
    }

    /**
     * The model the run row names: the fallback model once it was tried, else the main one.
     */
    public function modelOr(string $main): string
    {
        return $this->model ?? $main;
    }

    public function fromModel(string $main): ?string
    {
        return $this->attempted() ? $main : null;
    }
}
