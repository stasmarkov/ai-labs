<?php

declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class PhotoBestPickRules
{
    public const PROBLEMS = ['blurry', 'low_resolution', 'compression', 'cropped', 'too_dark', 'mirrored', 'other'];

    public const MAX_REASONS = 4;

    public const MAX_REASON_LENGTH = 120;

    /**
     * @param  list<int>  $groupIds  the ids of the photos in the group
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $groupIds): array
    {
        return [
            'best_photo_id' => ['required', 'integer', Rule::in($groupIds)],
            'reasons' => ['required', 'array', 'min:1', 'max:'.self::MAX_REASONS],
            'reasons.*' => ['string', 'min:1', 'max:'.self::MAX_REASON_LENGTH, new ValidUtf8Text],
            'issues' => ['present', 'array', 'max:'.max(0, count($groupIds) - 1)],
            'issues.*.photo_id' => ['required', 'integer', Rule::in($groupIds)],
            'issues.*.problem' => ['required', 'string', Rule::in(self::PROBLEMS)],
        ];
    }

    /**
     * The answer checked against the group: the pick and every issue name a photo of the group, and an issue is never
     * about the best photo.
     *
     * @param  array<string, mixed>  $output
     * @param  list<int>  $groupIds
     */
    public static function validator(array $output, array $groupIds): ValidatorContract
    {
        return Validator::make($output, self::rules($groupIds))->after(function (ValidatorContract $validator) use ($output): void {
            if ($validator->errors()->isNotEmpty() || ! is_array($output['issues'] ?? null)) {
                return;
            }

            foreach ($output['issues'] as $index => $issue) {
                if (($issue['photo_id'] ?? null) === $output['best_photo_id']) {
                    $validator->errors()->add("issues.{$index}.photo_id", 'An issue cannot name the best photo.');
                }
            }
        });
    }
}
