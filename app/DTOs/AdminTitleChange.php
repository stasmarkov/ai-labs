<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AdminTitleChange
{
    public function __construct(
        public int $id,
        public ?string $oldTitle,
        public string $newTitle,
        public bool $changed,
    ) {}
}
