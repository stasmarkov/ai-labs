<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DocsBenchQuery;
use App\DTOs\DocsBenchRow;
use App\DTOs\DocsMatch;
use App\DTOs\DocsSearchBenchReport;
use App\Enums\DocsSearchMode;
use App\Exceptions\DocsSearchUnavailableException;

final readonly class DocsSearchBenchService
{
    public const TOP = 3;

    /**
     * @var list<DocsSearchMode>
     */
    public const MODES = [DocsSearchMode::Vector, DocsSearchMode::Text, DocsSearchMode::Hybrid];

    public function __construct(private DocsSearchService $search) {}

    /**
     * Run every query in every retrieval mode and keep the files of the top chunks.
     *
     * @param  list<DocsBenchQuery>  $queries
     *
     * @throws DocsSearchUnavailableException when the model cannot embed a query.
     */
    public function run(array $queries): DocsSearchBenchReport
    {
        $rows = [];

        foreach ($queries as $query) {
            $top = [];
            $vector = $this->search->embedQuery($query->query);

            foreach (self::MODES as $mode) {
                $top[$mode->value] = array_map(
                    fn (DocsMatch $match): string => "{$match->source}:{$match->path}",
                    $this->search->search($query->query, $mode, self::TOP, $vector),
                );
            }

            $rows[] = new DocsBenchRow($query, $top);
        }

        return new DocsSearchBenchReport(self::TOP, array_map(fn (DocsSearchMode $mode): string => $mode->value, self::MODES), $rows);
    }
}
