<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class NlSearchInvalidException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  keyed by filter name
     * @param  array<string, mixed>  $extracted
     * @param  list<string>  $unsupported
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $extracted,
        public readonly array $unsupported,
        public readonly string $invocationId,
    ) {
        parent::__construct('The extracted filters failed validation.');
    }
}
