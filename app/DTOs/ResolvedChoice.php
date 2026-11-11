<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Ai\ProviderChoice;

/**
 * A provider choice and the level that decided it: request, settings, configuration or default. The model can come
 * from another level than the provider, for example a request that picks a provider and takes its default model.
 */
final readonly class ResolvedChoice
{
    public string $modelSource;

    public function __construct(
        public ProviderChoice $choice,
        public string $source,
        ?string $modelSource = null,
    ) {
        $this->modelSource = $modelSource ?? $source;
    }
}
