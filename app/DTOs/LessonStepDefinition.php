<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One validated step as written: a patch on the step before, KPI values as literals or result-file pointers, and text
 * that may still hold {kpi:id} and {num:file#path|format|round} placeholders.
 */
final readonly class LessonStepDefinition
{
    /**
     * @param  list<string>  $body
     * @param  array{kind: string, text: string}|null  $callout
     * @param  array{nodes?: array<string, array{load?: int, queue?: int, status?: string, badge?: ?string}>, edges?: array<string, array{visible?: bool, packets?: array<string, int>}>}  $state
     * @param  array<string, array{literal: string}|array{file: string, path: string, scale: int|float, round: ?int, trend: bool}>  $kpis
     * @param  list<array{sign: string, text: string}>  $changed
     * @param  list<string>  $learn
     */
    public function __construct(
        public int $number,
        public string $role,
        public string $title,
        public array $body,
        public ?array $callout,
        public array $state,
        public array $kpis,
        public array $changed,
        public array $learn,
        public ?string $deeper,
    ) {}
}
