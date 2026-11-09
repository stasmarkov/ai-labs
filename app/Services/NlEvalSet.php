<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\NlEvalQuery;
use App\Exceptions\RagEvalSetException;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Loads and validates the labelled natural-language queries (one JSON object per line).
 */
final readonly class NlEvalSet
{
    public const VERSION = 'v1';

    public static function defaultPath(): string
    {
        return base_path('bench/nl-search/queries.'.self::VERSION.'.jsonl');
    }

    /**
     * @return list<NlEvalQuery>
     *
     * @throws RagEvalSetException
     */
    public function load(?string $path = null): array
    {
        $path ??= self::defaultPath();

        if (! is_file($path)) {
            throw RagEvalSetException::missing($path);
        }

        $queries = [];
        $ids = [];

        foreach (preg_split('/\R/', File::get($path)) ?: [] as $index => $raw) {
            $line = $index + 1;

            if (trim($raw) === '') {
                continue;
            }

            $query = $this->parse($raw, $line);

            if (isset($ids[$query->id])) {
                throw RagEvalSetException::atLine($line, "duplicate id \"{$query->id}\" (first on line {$ids[$query->id]})");
            }

            $ids[$query->id] = $line;
            $queries[] = $query;
        }

        return $queries;
    }

    private function parse(string $raw, int $line): NlEvalQuery
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw RagEvalSetException::atLine($line, 'invalid JSON ('.$exception->getMessage().')');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw RagEvalSetException::atLine($line, 'expected a JSON object');
        }

        $id = $data['id'] ?? null;

        if (! is_string($id) || preg_match('/\A[a-z0-9-]{3,40}\z/', $id) !== 1) {
            throw RagEvalSetException::atLine($line, 'id must match [a-z0-9-]{3,40}');
        }

        $query = $data['query'] ?? null;

        if (! is_string($query) || trim($query) === '') {
            throw RagEvalSetException::atLine($line, '"query" must be a non-empty string');
        }

        $unsupported = $data['unsupported'] ?? null;

        if (! is_array($unsupported) || ! array_is_list($unsupported) || array_filter($unsupported, fn (mixed $term): bool => ! is_string($term) || trim($term) === '') !== []) {
            throw RagEvalSetException::atLine($line, '"unsupported" must be a list of non-empty strings');
        }

        return new NlEvalQuery($id, $query, $this->expected($data['expected'] ?? null, $line), $unsupported);
    }

    /**
     * @return array{make: ?string, model: ?string, city: ?string, fuel: ?string, min_year: ?int, max_price_eur: ?int}
     */
    private function expected(mixed $value, int $line): array
    {
        if (! is_array($value) || array_keys($value) !== NlSearchService::FILTER_FIELDS) {
            throw RagEvalSetException::atLine($line, '"expected" must hold exactly '.implode(', ', NlSearchService::FILTER_FIELDS).' in that order');
        }

        foreach ($value as $field => $item) {
            $isNumber = in_array($field, ['min_year', 'max_price_eur'], true);

            if ($item !== null && ! ($isNumber ? is_int($item) : is_string($item) && trim($item) !== '')) {
                throw RagEvalSetException::atLine($line, "expected \"{$field}\" must be null or ".($isNumber ? 'an integer' : 'a non-empty string'));
            }
        }

        return [
            'make' => $value['make'],
            'model' => $value['model'],
            'city' => $value['city'],
            'fuel' => $value['fuel'],
            'min_year' => $value['min_year'],
            'max_price_eur' => $value['max_price_eur'],
        ];
    }
}
