<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PhotoSearchEvalQuery;
use App\Exceptions\RagEvalSetException;
use App\Rules\PhotoExtractionRules;
use Database\Seeders\Catalog;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Loads and validates the labelled photo-search queries (one JSON object per line).
 */
final readonly class PhotoSearchEvalSet
{
    public const VERSION = 'v1';

    public const RULE_FIELDS = ['make', 'model', 'body_type', 'colour'];

    public static function defaultPath(): string
    {
        return base_path('bench/photo-search/queries.'.self::VERSION.'.jsonl');
    }

    /**
     * @return list<PhotoSearchEvalQuery>
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

    private function parse(string $raw, int $line): PhotoSearchEvalQuery
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

        if (! is_string($query) || mb_strlen(trim($query)) < PhotoTextSearchService::MIN_QUERY_LENGTH || mb_strlen($query) > PhotoTextSearchService::MAX_QUERY_LENGTH) {
            throw RagEvalSetException::atLine($line, '"query" must be '.PhotoTextSearchService::MIN_QUERY_LENGTH.' to '.PhotoTextSearchService::MAX_QUERY_LENGTH.' characters');
        }

        return new PhotoSearchEvalQuery($id, $query, $this->rule($data['rule'] ?? null, $line));
    }

    /**
     * @return array{make?: string, model?: string, body_type?: string, colour?: string}
     */
    private function rule(mixed $value, int $line): array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw RagEvalSetException::atLine($line, '"rule" must be an object with at least one of '.implode(', ', self::RULE_FIELDS));
        }

        $rule = [];

        foreach ($value as $field => $item) {
            if (! in_array($field, self::RULE_FIELDS, true) || ! is_string($item) || trim($item) === '') {
                throw RagEvalSetException::atLine($line, 'rule fields are '.implode(', ', self::RULE_FIELDS).', each a non-empty string');
            }

            $allowed = match ($field) {
                'make' => array_keys(Catalog::MODELS_BY_MAKE),
                'model' => array_keys(Catalog::BODY_TYPE_BY_MODEL),
                'body_type' => array_values(array_unique(Catalog::BODY_TYPE_BY_MODEL)),
                default => PhotoExtractionRules::COLOURS,
            };

            if (! in_array($item, $allowed, true)) {
                throw RagEvalSetException::atLine($line, "rule {$field} \"{$item}\" is not in the catalogue");
            }

            $rule[$field] = $item;
        }

        return $rule;
    }
}
