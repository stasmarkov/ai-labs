<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The text and settings of a prompt version before it is stored. The text is trimmed and its line endings are `\n`; the
 * temperature has two decimals, the precision it is stored with, so what is compared, sent and stored is one value.
 */
final readonly class NewPlaygroundVersion
{
    public string $systemPrompt;

    public float $temperature;

    public function __construct(
        string $systemPrompt,
        public ?string $note = null,
        float $temperature = 0.2,
        public int $maxTokens = 512,
    ) {
        $this->systemPrompt = trim(str_replace(["\r\n", "\r"], "\n", $systemPrompt));
        $this->temperature = round($temperature, 2);
    }

    /**
     * A save is unchanged when the text and both settings equal the version's; a note-only change counts as unchanged.
     */
    public function isUnchangedFrom(PlaygroundVersionRecord $latest): bool
    {
        return $this->systemPrompt === $latest->systemPrompt
            && $this->temperature === $latest->temperature
            && $this->maxTokens === $latest->maxTokens;
    }
}
