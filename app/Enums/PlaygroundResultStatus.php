<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How one target of a playground run ended.
 */
enum PlaygroundResultStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
    case Timeout = 'timeout';
}
