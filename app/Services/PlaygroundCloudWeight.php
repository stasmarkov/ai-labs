<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviders;
use App\DTOs\PlaygroundTarget;

/**
 * What the cloud budget weighs a playground run by: its number of targets on a cloud provider. It needs only the
 * provider table, so the limiter can ask without building the runner and its repositories on every AI route.
 */
final readonly class PlaygroundCloudWeight
{
    public function __construct(private AiProviders $providers) {}

    /**
     * Unknown and disabled providers count as cloud, so a request that is refused later cannot slip under the budget.
     *
     * @param  list<PlaygroundTarget>  $targets
     */
    public function cloudTargets(array $targets): int
    {
        return count(array_filter(
            $targets,
            fn (PlaygroundTarget $target): bool => ! ($this->providers->isEnabledTextProvider($target->provider) && ! $this->providers->isCloud($target->provider)),
        ));
    }
}
