<?php

declare(strict_types=1);

namespace App\Contracts;

interface SemanticBenchRepositoryInterface
{
    /**
     * Ids of the nearest embedded listings as the planner chooses (the HNSW index when it is used).
     *
     * @param  list<float>  $vector
     * @return list<int>
     */
    public function approximateIds(array $vector, int $limit, int $efSearch): array;

    /**
     * Ids of the nearest embedded listings from an exact scan with index scans switched off.
     *
     * @param  list<float>  $vector
     * @return list<int>
     */
    public function exactIds(array $vector, int $limit): array;

    public function efSearch(): int;

    public function pgvectorVersion(): string;

    public function embeddedCount(): int;

    /**
     * Drop and recreate the HNSW index; returns the seconds the CREATE INDEX took.
     */
    public function rebuildIndex(): float;
}
