<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\DTOs\ListingFilter;
use App\DTOs\ListingSummary;
use App\Enums\Fuel;
use App\Rules\ListingFilterRules;
use App\Services\ListingSearchService;
use App\Services\PromptInjectionGuard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Tools\Request;

/**
 * The buyer assistant's one tool: the existing listings search. Arguments reach the search only after
 * ListingFilterRules accepted them. One instance serves one turn: it counts the calls and the invalid ones.
 */
final class SearchListings implements ReadOnlyTool
{
    public const MAX_CALLS = 4;

    public const MAX_LIMIT = 10;

    public const DEFAULT_LIMIT = 5;

    public const MAX_INVALID = 2;

    private const TEXT_FIELDS = ['make', 'model', 'city'];

    private int $calls = 0;

    private int $invalid = 0;

    private bool $gaveUp = false;

    public function __construct(
        private readonly ListingSearchService $listings,
        private readonly PromptInjectionGuard $guard,
    ) {}

    public function description(): string
    {
        return 'Search the used-car listings. Every argument is optional: make, model, city, fuel ('
            .implode(', ', array_column(Fuel::cases(), 'value')).'), min_year (earliest model year), max_price (highest price in whole euros) and limit '
            .'(listings to return, default '.self::DEFAULT_LIMIT.', at most '.self::MAX_LIMIT.'). Returns the matching listings, newest first, with their ids and url paths.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'make' => $schema->string()->description('Car make, for example "Volkswagen".'),
            'model' => $schema->string()->description('Car model, for example "Golf".'),
            'city' => $schema->string()->description('City of the listing.'),
            'fuel' => $schema->string()->enum(array_column(Fuel::cases(), 'value'))->description('Fuel type.'),
            'min_year' => $schema->integer()->description('Earliest model year, a four-digit integer.'),
            'max_price' => $schema->integer()->description('Highest price in whole euros.'),
            'limit' => $schema->integer()->description('How many listings to return, 1 to '.self::MAX_LIMIT.'.'),
        ];
    }

    public function handle(Request $request): string
    {
        if ($this->gaveUp) {
            return $this->final('gave_up', 'The search was given up after repeated invalid arguments. Tell the user what you could not search.');
        }

        if ($this->calls >= self::MAX_CALLS) {
            return $this->final('step_limit', 'The search limit for this question is reached. Answer with what you have.');
        }

        $this->calls++;
        $arguments = self::normalise($request->all());
        $errors = $this->errors($arguments);

        if ($errors !== []) {
            $this->invalid++;

            if ($this->invalid >= self::MAX_INVALID) {
                $this->gaveUp = true;

                return $this->encode(['error' => 'gave_up', 'final' => true, 'errors' => $errors, 'message' => 'The arguments were invalid twice. Do not search again; tell the user what you could not search.']);
            }

            return $this->encode(['error' => 'invalid_arguments', 'final' => false, 'errors' => $errors, 'message' => 'Fix the listed arguments and call the tool once more.']);
        }

        $data = array_filter(
            array_intersect_key($arguments, ListingFilterRules::rules()),
            fn (mixed $value): bool => $value !== null,
        );
        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $page = $this->listings->search(ListingFilter::fromValidated($data, $limit));

        return $this->encode([
            'total' => $page->total,
            'shown' => min(count($page->items), $limit),
            'listings' => array_map($this->compact(...), array_slice($page->items, 0, $limit)),
        ]);
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function invalidCalls(): int
    {
        return $this->invalid;
    }

    public function gaveUp(): bool
    {
        return $this->gaveUp;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, list<string>>
     */
    private function errors(array $arguments): array
    {
        $unknown = array_diff_key($arguments, ListingFilterRules::rules() + ['limit' => true]);
        $validator = Validator::make($arguments, ListingFilterRules::rules() + [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);
        $validator->after(function ($validator) use ($arguments): void {
            foreach (['min_year', 'max_price', 'limit'] as $field) {
                $value = $arguments[$field] ?? null;

                if ($value !== null && ! is_int($value) && ! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, "The {$field} field must be an integer.");
                }
            }

            foreach (self::TEXT_FIELDS as $field) {
                $value = $arguments[$field] ?? null;

                if (is_string($value) && $this->guard->match($value) !== null && ! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, "The {$field} field is not a valid search value.");
                }
            }
        });

        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        foreach (array_keys($unknown) as $field) {
            $errors[(string) $field] = ["The {$field} argument is not supported."];
        }

        return $errors;
    }

    /**
     * @return array{id: int, url: string, make: string, model: string, year: int, fuel: string, price_eur: int, mileage_km: int, city: string}
     */
    private function compact(ListingSummary $listing): array
    {
        return [
            'id' => $listing->id,
            'url' => '/listings/'.$listing->id,
            'make' => $listing->make,
            'model' => $listing->model,
            'year' => $listing->year,
            'fuel' => $listing->fuel,
            'price_eur' => intdiv($listing->priceCents, 100),
            'mileage_km' => $listing->mileageKm,
            'city' => $listing->city,
        ];
    }

    private function final(string $error, string $message): string
    {
        return $this->encode(['error' => $error, 'final' => true, 'message' => $message]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The HTTP path trims strings and turns blanks into null in middleware; a tool call skips that, so it happens here.
     * Small models often send "" for an unused optional argument, which would otherwise pass the rules and fail later.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function normalise(array $arguments): array
    {
        $clean = [];

        foreach ($arguments as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;

            if ($value !== '' && $value !== null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
