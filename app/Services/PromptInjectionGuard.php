<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final readonly class PromptInjectionGuard
{
    /**
     * @param  array<string, string>  $patterns  name => regex
     */
    public function __construct(private array $patterns) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("Prompt-injection guard file not found: {$path}");
        }

        /** @var array<string, string> $patterns */
        $patterns = require $path;

        return new self($patterns);
    }

    /**
     * The name of the first pattern the question matches, or null when it looks benign.
     */
    public function match(string $question): ?string
    {
        foreach ($this->patterns as $name => $pattern) {
            if (preg_match($pattern, $question) === 1) {
                return $name;
            }
        }

        return null;
    }
}
