<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\DocsMatch;
use App\Enums\DocsSearchMode;
use App\Exceptions\DocsSearchUnavailableException;
use App\Services\DocsSearchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('docs:search {query : What to look for} {--mode=hybrid : hybrid, vector or text} {--k= : Chunks to return (default from config)}')]
#[Description('Search the indexed docs by vector similarity, full text, or both fused with RRF')]
final class SearchDocsCommand extends Command
{
    public function handle(DocsSearchService $service): int
    {
        $query = trim((string) $this->argument('query'));
        $mode = DocsSearchMode::tryFrom((string) $this->option('mode'));
        $k = $this->option('k') === null ? $service->defaultK() : filter_var($this->option('k'), FILTER_VALIDATE_INT);

        if ($query === '' || mb_strlen($query) > DocsSearchService::MAX_QUERY_LENGTH) {
            $this->error('The query must be 1 to '.DocsSearchService::MAX_QUERY_LENGTH.' characters.');

            return self::FAILURE;
        }

        if ($mode === null) {
            $this->error('--mode must be one of: '.implode(', ', array_column(DocsSearchMode::cases(), 'value')).'.');

            return self::FAILURE;
        }

        if ($k === false || $k < 1 || $k > $service->maxK()) {
            $this->error("--k must be a whole number from 1 to {$service->maxK()}.");

            return self::FAILURE;
        }

        try {
            $matches = $service->search($query, $mode, $k);
        } catch (DocsSearchUnavailableException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Rank', 'Score', 'Source', 'Path', 'Heading', 'Snippet'],
            array_map(fn (DocsMatch $match, int $index): array => [
                $index + 1,
                number_format($match->score, 6),
                $match->source,
                $match->path,
                $match->heading ?? '',
                str_replace(["\r", "\n"], ' ', $match->snippet(80)),
            ], $matches, array_keys($matches)),
        );

        return self::SUCCESS;
    }
}
