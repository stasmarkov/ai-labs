<?php

declare(strict_types=1);

namespace App\Enums;

enum DuplicateReviewSource: string
{
    case Model = 'model';
    case Override = 'override';
}
