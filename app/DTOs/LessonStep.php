<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One resolved step: the complete scene state, the KPI values and the text, with every number already read from a file.
 */
final readonly class LessonStep
{
    /**
     * @param  list<string>  $body
     * @param  array{kind: string, text: string}|null  $callout
     * @param  array<string, array{load: int, queue: int, status: string, badge: ?string}>  $nodes
     * @param  array<string, array{visible: bool, packets: array<string, int>}>  $edges
     * @param  list<array{id: string, label: string, value: string, trend: string}>  $kpis
     * @param  list<array{sign: string, text: string}>  $changed
     * @param  list<string>  $learn
     */
    public function __construct(
        public int $number,
        public string $role,
        public string $title,
        public array $body,
        public ?array $callout,
        public array $nodes,
        public array $edges,
        public array $kpis,
        public array $changed,
        public array $learn,
        public ?string $deeper,
    ) {}
}
