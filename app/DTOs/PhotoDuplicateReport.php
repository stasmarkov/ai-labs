<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoDuplicateReport
{
    public function __construct(
        public int $removed,
        public int $originals,
        public int $variantsStored,
        public int $alreadyStored,
        public int $unreadableOriginals,
    ) {}
}
