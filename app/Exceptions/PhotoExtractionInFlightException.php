<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\DTOs\PhotoLabDetail;
use RuntimeException;

final class PhotoExtractionInFlightException extends RuntimeException
{
    public function __construct(public readonly PhotoLabDetail $current)
    {
        parent::__construct("Photo {$current->id} is already {$current->status}.");
    }
}
