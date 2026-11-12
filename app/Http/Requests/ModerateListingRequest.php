<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\ModerationInput;
use App\Http\Requests\Concerns\NamesProvider;
use App\Rules\ValidUtf8Text;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

final class ModerateListingRequest extends FormRequest
{
    use NamesProvider;

    public const MAX_TITLE_LENGTH = 120;

    public const MAX_NAME_LENGTH = 60;

    public const MAX_CITY_LENGTH = 80;

    public const MAX_DESCRIPTION_LENGTH = 2000;

    public const MAX_PRICE_EUR = 100_000_000;

    public const MAX_MILEAGE_KM = 5_000_000;

    public const MIN_YEAR = 1886;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only a JSON body is accepted, which keeps plain forms and links from starting a model run. It does not stop another
     * site's script: "api/*" answers CORS preflights with a wildcard origin, so the route also refuses cross-site requests
     * (RefuseCrossSiteRequests).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            throw new UnsupportedMediaTypeHttpException('Send the listing as a JSON body.');
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE_LENGTH, new ValidUtf8Text],
            'price_eur' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_PRICE_EUR],
            'make' => ['required', 'string', 'max:'.self::MAX_NAME_LENGTH, new ValidUtf8Text],
            'model' => ['required', 'string', 'max:'.self::MAX_NAME_LENGTH, new ValidUtf8Text],
            'year' => ['required', 'integer', 'min:'.self::MIN_YEAR, 'max:'.(now()->year + 1)],
            'mileage_km' => ['required', 'integer', 'min:0', 'max:'.self::MAX_MILEAGE_KM],
            'city' => ['required', 'string', 'max:'.self::MAX_CITY_LENGTH, new ValidUtf8Text],
            'description' => ['required', 'string', 'max:'.self::MAX_DESCRIPTION_LENGTH, new ValidUtf8Text],
            'listing_id' => ['nullable', 'integer', 'min:1', 'max:999999999999999999'],
            'provider' => $this->providerRules(),
        ];
    }

    public function toInput(): ModerationInput
    {
        return new ModerationInput(
            title: trim((string) $this->validated('title')),
            priceEur: (float) $this->validated('price_eur'),
            make: trim((string) $this->validated('make')),
            model: trim((string) $this->validated('model')),
            year: (int) $this->validated('year'),
            mileageKm: (int) $this->validated('mileage_km'),
            city: trim((string) $this->validated('city')),
            description: trim((string) $this->validated('description')),
            listingId: filled($this->validated('listing_id')) ? (int) $this->validated('listing_id') : null,
        );
    }
}
