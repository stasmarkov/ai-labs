<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Ai\DocsAnswerAgent;
use App\Ai\OutputGuard;

/**
 * What the shared steps of answering decided: either an outcome that needs no model call, or the agent, sources and
 * output guard for one, or a cached answer to serve.
 */
final readonly class DocsAnswerPlan
{
    /**
     * @param  list<DocsMatch>  $sources
     */
    public function __construct(
        public DocsAnswerAgent $agent,
        public array $sources,
        public ?OutputGuard $guard,
        public ?DocsAnswerResult $early = null,
        public ?string $pattern = null,
        public ?DocsCacheHit $hit = null,
        /** @var list<float>|null the question vector, set when the cache is on */
        public ?array $vector = null,
        /** the cache versions read once when the request was planned; null when the cache is off or unreadable */
        public ?DocsCacheVersions $versions = null,
    ) {}
}
