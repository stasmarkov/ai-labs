<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ExportDeliveryMode;

/**
 * How one download will be answered. The header pair is set only when the web server sends the file (mode Offload).
 */
final readonly class ExportDelivery
{
    public function __construct(
        public string $id,
        public string $path,
        public int $bytes,
        public ExportDeliveryMode $mode,
        public ?string $headerName,
        public ?string $headerValue,
    ) {}
}
