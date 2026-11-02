<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DocsBenchRow
{
    /**
     * @param  array<string, list<string>>  $top  the "source:path" of each of the top chunks, by mode
     */
    public function __construct(
        public DocsBenchQuery $query,
        public array $top,
    ) {}

    /**
     * 1-based position of the first top chunk that belongs to an expected file, or null.
     */
    public function rank(string $mode): ?int
    {
        foreach ($this->top[$mode] ?? [] as $index => $file) {
            if (in_array($file, $this->query->expected, true)) {
                return $index + 1;
            }
        }

        return null;
    }

    public function passed(string $mode): bool
    {
        return $this->rank($mode) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = ['type' => $this->query->type, 'query' => $this->query->query, 'expected' => $this->query->expected];

        foreach ($this->top as $mode => $files) {
            $row[$mode] = ['passed' => $this->passed($mode), 'rank' => $this->rank($mode), 'top' => $files];
        }

        return $row;
    }
}
