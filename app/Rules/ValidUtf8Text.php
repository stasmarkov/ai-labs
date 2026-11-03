<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * PostgreSQL rejects NUL bytes and invalid UTF-8 with an error, so they are a 422 at the edge instead of a 500 there.
 */
final class ValidUtf8Text implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8'))) {
            $fail('The :attribute must be valid UTF-8 text.');
        }
    }
}
