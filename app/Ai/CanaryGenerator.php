<?php

declare(strict_types=1);

namespace App\Ai;

use Closure;

/**
 * Makes the per-request canary appended to the system prompt. A test passes a fixed factory.
 */
final readonly class CanaryGenerator
{
    /**
     * @param  Closure(): string|null  $factory
     */
    public function __construct(private ?Closure $factory = null) {}

    public function generate(): string
    {
        return $this->factory === null ? 'ap-canary-'.bin2hex(random_bytes(8)) : ($this->factory)();
    }
}
