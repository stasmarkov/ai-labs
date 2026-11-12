<?php

declare(strict_types=1);

namespace App\Enums;

enum PhotoReviewStatus: string
{
    case Kept = 'kept';
    case MarkedForRemoval = 'marked_for_removal';
}
