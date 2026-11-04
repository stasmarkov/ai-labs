<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How one download is actually answered, and the value of the X-Export-Mode response header and of the log's mode field.
 */
enum ExportDeliveryMode: string
{
    case Offload = 'offload';
    case StreamFallback = 'stream-fallback';
    case Stream = 'stream';

    public function streamsFromPhp(): bool
    {
        return $this !== self::Offload;
    }
}
