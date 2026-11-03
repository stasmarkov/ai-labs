<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The chunk sizes the indexed documents were made with. Documents indexed before the sizes were recorded count as 500 / 300.
 */
final readonly class RagChunkSettings
{
    public const LEGACY_MAX_TOKENS = 500;

    public const LEGACY_MERGE_BELOW_TOKENS = 300;

    /**
     * @param  list<array{max_tokens: int, merge_below_tokens: int}>  $found  each distinct pair in the index, not empty
     */
    public function __construct(public array $found) {}

    public function isMixed(): bool
    {
        return count($this->found) > 1;
    }

    public function matches(int $maxTokens, int $mergeBelowTokens): bool
    {
        return ! $this->isMixed()
            && $this->found[0]['max_tokens'] === $maxTokens
            && $this->found[0]['merge_below_tokens'] === $mergeBelowTokens;
    }

    public function describe(): string
    {
        return implode(' and ', array_map(fn (array $pair): string => "{$pair['max_tokens']}/{$pair['merge_below_tokens']}", $this->found));
    }

    /**
     * @return array{max_tokens: int|null, merge_below_tokens: int|null, mixed: bool, found: list<array{max_tokens: int, merge_below_tokens: int}>}
     */
    public function toArray(): array
    {
        return [
            'max_tokens' => $this->isMixed() ? null : $this->found[0]['max_tokens'],
            'merge_below_tokens' => $this->isMixed() ? null : $this->found[0]['merge_below_tokens'],
            'mixed' => $this->isMixed(),
            'found' => $this->found,
        ];
    }
}
