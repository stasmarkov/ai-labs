<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\DocsCacheWindowStats;
use App\Services\DocsCacheMaintenance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('docs:cache
    {--stats : Print hit rate, hits by kind, saved latency and current vs stale entries}
    {--prune : Delete stale and expired entries and their exact keys}')]
#[Description('Show statistics for the docs answer cache or prune its stale entries')]
final class DocsCacheCommand extends Command
{
    public function handle(DocsCacheMaintenance $maintenance): int
    {
        if (! $this->option('stats') && ! $this->option('prune')) {
            $this->error('Pass --stats, --prune or both.');

            return self::INVALID;
        }

        if ($this->option('prune')) {
            $this->info("Pruned {$maintenance->prune()} entries.");
        }

        if ($this->option('stats')) {
            $stats = $maintenance->stats();

            $this->table(
                ['Window', 'Hit rate', 'Hits', 'Completed', 'Hits by kind', 'Saved sum ms', 'Saved median ms'],
                [$this->row('Last 24 h', $stats->lastDay), $this->row('All time', $stats->allTime)],
            );
            $this->line("Entries: {$stats->currentEntries} current, {$stats->staleEntries} stale.");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function row(string $label, DocsCacheWindowStats $window): array
    {
        $kinds = $window->hitsByKind === []
            ? '-'
            : implode(', ', array_map(fn (string $kind, int $count): string => "{$kind}: {$count}", array_keys($window->hitsByKind), $window->hitsByKind));

        return [
            $label,
            $window->hitRate === null ? 'n/a' : number_format($window->hitRate * 100, 1).'%',
            (string) $window->hits,
            (string) $window->completed,
            $kinds,
            (string) $window->savedLatencySumMs,
            $window->savedLatencyMedianMs === null ? 'n/a' : (string) round($window->savedLatencyMedianMs, 1),
        ];
    }
}
