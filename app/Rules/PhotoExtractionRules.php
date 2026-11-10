<?php

declare(strict_types=1);

namespace App\Rules;

use Illuminate\Validation\Rule;

final class PhotoExtractionRules
{
    public const COLOURS = ['black', 'white', 'grey', 'silver', 'red', 'blue', 'green', 'yellow', 'orange', 'brown', 'beige', 'gold', 'purple'];

    public const BODY_TYPES = ['hatchback', 'saloon', 'estate', 'SUV', 'MPV', 'coupe', 'convertible', 'pickup', 'van', 'unknown'];

    public const DAMAGE_LEVELS = ['none', 'minor', 'major', 'unknown'];

    public const MAX_CAPTION_LENGTH = 200;

    public const MAX_TAGS = 8;

    public const MAX_TAG_LENGTH = 24;

    public const MAX_COLOURS = 3;

    public const MAX_PEOPLE = 20;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'caption' => ['required', 'string', 'min:1', 'max:'.self::MAX_CAPTION_LENGTH, new ValidUtf8Text],
            'tags' => ['present', 'array', 'max:'.self::MAX_TAGS],
            'tags.*' => ['string', 'min:1', 'max:'.self::MAX_TAG_LENGTH, 'lowercase', new ValidUtf8Text],
            'dominant_colours' => ['required', 'array', 'min:1', 'max:'.self::MAX_COLOURS],
            'dominant_colours.*' => ['string', Rule::in(self::COLOURS)],
            'body_type' => ['required', 'string', Rule::in(self::BODY_TYPES)],
            'visible_damage' => ['required', 'string', Rule::in(self::DAMAGE_LEVELS)],
            'people_count' => ['required', 'integer', 'min:0', 'max:'.self::MAX_PEOPLE],
        ];
    }
}
