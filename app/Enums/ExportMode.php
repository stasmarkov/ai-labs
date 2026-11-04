<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the export file reaches the client: the web server sends it after PHP hands over the path, or PHP writes it.
 */
enum ExportMode: string
{
    case Offload = 'offload';
    case Stream = 'stream';
}
