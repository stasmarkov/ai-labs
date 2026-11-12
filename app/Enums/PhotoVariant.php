<?php

declare(strict_types=1);

namespace App\Enums;

enum PhotoVariant: string
{
    case Downscaled = 'downscaled';
    case Recompressed = 'recompressed';
    case Cropped = 'cropped';
    case Darkened = 'darkened';
    case Mirrored = 'mirrored';
}
