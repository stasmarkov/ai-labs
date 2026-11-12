<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class PhotoNotInDuplicateGroupException extends RuntimeException
{
    public function __construct(public readonly int $groupId, public readonly int $photoId)
    {
        parent::__construct("Photo {$photoId} is not in duplicate group {$groupId}.");
    }
}
