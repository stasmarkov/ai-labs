<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ModelOption
{
    public const TEXT = 'text';

    public const EMBEDDING = 'embedding';

    public function __construct(
        public string $name,
        public string $kind,
        public bool $vision = false,
    ) {}

    /**
     * @return array{name: string, kind: string, vision: bool}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'kind' => $this->kind, 'vision' => $this->vision];
    }
}
