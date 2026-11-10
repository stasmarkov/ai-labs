<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One enabled text provider in the lab selector: its name and whether the feature is configured to use it, and whether it can run (a cloud provider needs a key, never exposed).
 */
final readonly class LabProviderOption
{
    public function __construct(
        public string $name,
        public bool $configured,
        public bool $hasKey = true,
    ) {}

    /**
     * @return array{name: string, configured: bool, hasKey: bool}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'configured' => $this->configured, 'hasKey' => $this->hasKey];
    }
}
