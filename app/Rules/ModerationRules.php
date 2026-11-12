<?php

declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ModerationRules
{
    public const REASONS = ['advance_payment', 'off_platform_contact', 'price_far_below_market', 'reported_vin', 'pressure_tactics', 'inconsistent_details', 'other'];

    public const MAX_REASONS = 4;

    public const MAX_EXPLANATION_LENGTH = 300;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'risk' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value) && ! is_float($value)) {
                    $fail('The risk must be a number.');
                } elseif ($value < 0 || $value > 1) {
                    $fail('The risk must be between 0 and 1.');
                }
            }],
            'reasons' => ['present', 'array', 'max:'.self::MAX_REASONS],
            'reasons.*' => ['distinct', 'string', Rule::in(self::REASONS)],
            'explanation' => ['required', 'string', 'min:1', 'max:'.self::MAX_EXPLANATION_LENGTH, new ValidUtf8Text],
        ];
    }

    /**
     * An explanation over the limit is cut, not rejected; every other field is checked as sent.
     *
     * @param  array<string, mixed>  $output
     */
    public static function validator(array $output): ValidatorContract
    {
        if (is_string($output['explanation'] ?? null) && mb_check_encoding($output['explanation'], 'UTF-8')) {
            $output['explanation'] = mb_substr(trim($output['explanation']), 0, self::MAX_EXPLANATION_LENGTH);
        }

        return Validator::make($output, self::rules());
    }
}
