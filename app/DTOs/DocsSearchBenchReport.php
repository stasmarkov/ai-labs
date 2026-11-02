<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DocsSearchBenchReport
{
    public const HYBRID_MINIMUM = 4;

    /**
     * @param  list<DocsBenchRow>  $rows
     * @param  list<string>  $modes
     */
    public function __construct(
        public int $k,
        public array $modes,
        public array $rows,
    ) {}

    /**
     * Queries answered with an expected file in the top k, per query type and mode.
     *
     * @return array<string, array<string, int>>
     */
    public function summary(): array
    {
        $summary = [];

        foreach ([DocsBenchQuery::IDENTIFIER, DocsBenchQuery::MEANING] as $type) {
            $rows = array_filter($this->rows, fn (DocsBenchRow $row): bool => $row->query->type === $type);
            $summary[$type] = ['total' => count($rows)];

            foreach ($this->modes as $mode) {
                $summary[$type][$mode] = count(array_filter($rows, fn (DocsBenchRow $row): bool => $row->passed($mode)));
            }
        }

        return $summary;
    }

    /**
     * Whether hybrid found the expected file for at least 4 identifier queries and at least 4 meaning queries.
     */
    public function thresholdsMet(): bool
    {
        $summary = $this->summary();

        return ($summary[DocsBenchQuery::IDENTIFIER]['hybrid'] ?? 0) >= self::HYBRID_MINIMUM
            && ($summary[DocsBenchQuery::MEANING]['hybrid'] ?? 0) >= self::HYBRID_MINIMUM;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'k' => $this->k,
            'modes' => $this->modes,
            'queries' => count($this->rows),
            'rows' => array_map(fn (DocsBenchRow $row): array => $row->toArray(), $this->rows),
            'summary' => $this->summary(),
            'thresholds' => [
                'hybrid_identifier_min' => self::HYBRID_MINIMUM,
                'hybrid_meaning_min' => self::HYBRID_MINIMUM,
                'met' => $this->thresholdsMet(),
            ],
        ];
    }
}
