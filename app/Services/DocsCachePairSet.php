<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DocsCachePair;
use App\Exceptions\DocsCachePairException;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Loads and validates the labelled paraphrase and near-miss pairs (one JSON object per line).
 */
final readonly class DocsCachePairSet
{
    public const VERSION = 'v1';

    public const MIN_PER_LABEL = 20;

    private const LABELS = [DocsCachePair::PARAPHRASE, DocsCachePair::NEAR_MISS];

    public static function defaultPath(): string
    {
        return base_path('bench/rag-eval/cache-pairs.'.self::VERSION.'.jsonl');
    }

    /**
     * @return list<DocsCachePair>
     *
     * @throws DocsCachePairException
     */
    public function load(?string $path = null): array
    {
        $path ??= self::defaultPath();

        if (! is_file($path)) {
            throw DocsCachePairException::missing($path);
        }

        $pairs = [];
        $ids = [];
        $counts = array_fill_keys(self::LABELS, 0);

        foreach (preg_split('/\R/', File::get($path)) ?: [] as $index => $raw) {
            $line = $index + 1;

            if (trim($raw) === '') {
                continue;
            }

            $pair = $this->parse($raw, $line);

            if (isset($ids[$pair->id])) {
                throw DocsCachePairException::atLine($line, "duplicate id \"{$pair->id}\" (first on line {$ids[$pair->id]})");
            }

            $ids[$pair->id] = $line;
            $counts[$pair->label]++;
            $pairs[] = $pair;
        }

        foreach ($counts as $label => $count) {
            if ($count < self::MIN_PER_LABEL) {
                throw DocsCachePairException::tooFew($label, $count, self::MIN_PER_LABEL);
            }
        }

        return $pairs;
    }

    private function parse(string $raw, int $line): DocsCachePair
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw DocsCachePairException::atLine($line, 'invalid JSON ('.$exception->getMessage().')');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw DocsCachePairException::atLine($line, 'expected a JSON object');
        }

        $id = $this->string($data, 'id', $line);

        if (preg_match('/\A[a-z0-9-]{3,40}\z/', $id) !== 1) {
            throw DocsCachePairException::atLine($line, 'id must match [a-z0-9-]{3,40}');
        }

        $label = $this->string($data, 'label', $line);

        if (! in_array($label, self::LABELS, true)) {
            throw DocsCachePairException::atLine($line, 'label must be paraphrase or near_miss');
        }

        $a = trim($this->string($data, 'a', $line));
        $b = trim($this->string($data, 'b', $line));

        if (RagEvalSet::normalizeText($a) === RagEvalSet::normalizeText($b)) {
            throw DocsCachePairException::atLine($line, '"a" and "b" must differ');
        }

        return new DocsCachePair($id, $label, $a, $b, $line);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(array $data, string $key, int $line): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw DocsCachePairException::atLine($line, "\"{$key}\" must be a non-empty string");
        }

        return $value;
    }
}
