<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DocsIndexRun
{
    /**
     * @param  list<string>  $skippedSources  sources whose folder is missing
     */
    public function __construct(
        public int $documentsChanged,
        public int $documentsUnchanged,
        public int $documentsRemoved,
        public int $chunks,
        public int $jobs,
        public int $batch,
        public array $skippedSources,
    ) {}
}
