<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The provider entry name, its SDK driver and the model one feature runs on. Holds no key.
 */
final readonly class ProviderChoice
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $driver,
    ) {}
}
