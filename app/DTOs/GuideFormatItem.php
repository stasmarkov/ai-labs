<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One step, stage, question, scenario or tour stop of a guide format file, validated.
 */
final readonly class GuideFormatItem
{
    /**
     * @param  list<string>  $outcomes  The learning outcome ids the item teaches.
     * @param  string|null  $snippet  The code reference (`App\Class::method`), resolved separately by the repository.
     * @param  array<string, mixed>  $fields  The format's own fields (goal, sample, options, causes, notes, ...).
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $outcomes,
        public ?string $snippet,
        public array $fields,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'outcomes' => $this->outcomes, 'snippet' => $this->snippet, ...$this->fields];
    }
}
