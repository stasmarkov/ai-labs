<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\DocsAnswerOutcome;

final readonly class DocsAnswerResult
{
    /**
     * @param  string  $text  the answer, or the message that stands in for it when there is none
     * @param  list<DocsMatch>  $sources  in citation order; the first is source 1
     * @param  list<int>  $citedIds  chunk ids of the sources the answer cites, in order of first use
     */
    public function __construct(
        public DocsAnswerOutcome $outcome,
        public string $text,
        public array $sources,
        public array $citedIds,
        public string $invocationId,
    ) {}
}
