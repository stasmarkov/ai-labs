<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ModerationSetItem;
use App\Exceptions\RagEvalSetException;
use App\Rules\ModerationRules;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Loads and validates the labelled moderation items (one JSON object per line).
 */
final readonly class ModerationEvalSet
{
    public const VERSION = 'v1';

    public static function defaultPath(): string
    {
        return base_path('bench/moderation/listings.'.self::VERSION.'.jsonl');
    }

    /**
     * The safe items written by hand to look suspicious carry the kind "hard_negative" in the set file.
     */
    public static function isHardNegative(ModerationSetItem $item): bool
    {
        return $item->kind === 'hard_negative';
    }

    /**
     * @return list<ModerationSetItem>
     *
     * @throws RagEvalSetException
     */
    public function load(?string $path = null): array
    {
        $path ??= self::defaultPath();

        if (! is_file($path)) {
            throw RagEvalSetException::missing($path);
        }

        $items = [];
        $ids = [];

        foreach (preg_split('/\R/', File::get($path)) ?: [] as $index => $raw) {
            $line = $index + 1;

            if (trim($raw) === '') {
                continue;
            }

            $item = $this->parse($raw, $line);

            if (isset($ids[$item->id])) {
                throw RagEvalSetException::atLine($line, "duplicate id \"{$item->id}\" (first on line {$ids[$item->id]})");
            }

            $ids[$item->id] = $line;
            $items[] = $item;
        }

        if ($items === []) {
            throw RagEvalSetException::atLine(1, 'the set has no items');
        }

        return $items;
    }

    private function parse(string $raw, int $line): ModerationSetItem
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw RagEvalSetException::atLine($line, 'invalid JSON ('.$e->getMessage().')');
        }

        if (! is_array($data)) {
            throw RagEvalSetException::atLine($line, 'expected a JSON object');
        }

        foreach (['id', 'title', 'make', 'model', 'city', 'description'] as $key) {
            if (! is_string($data[$key] ?? null) || $data[$key] === '') {
                throw RagEvalSetException::atLine($line, "\"{$key}\" must be a non-empty string");
            }
        }

        foreach (['year', 'price_eur', 'mileage_km'] as $key) {
            if (! is_int($data[$key] ?? null)) {
                throw RagEvalSetException::atLine($line, "\"{$key}\" must be an integer");
            }
        }

        if (! in_array($data['label'] ?? null, ['safe', 'risky'], true)) {
            throw RagEvalSetException::atLine($line, '"label" must be "safe" or "risky"');
        }

        $kinds = $data['label'] === 'safe' ? ['safe', 'hard_negative'] : ['risky', 'mixed'];

        if (! in_array($data['kind'] ?? null, $kinds, true)) {
            throw RagEvalSetException::atLine($line, '"kind" must be '.implode(' or ', $kinds).' for a '.$data['label'].' item');
        }

        $reasons = $data['reasons'] ?? null;

        if (! is_array($reasons) || ! array_is_list($reasons) || array_diff($reasons, ModerationRules::REASONS) !== []) {
            throw RagEvalSetException::atLine($line, '"reasons" must be a list from the closed reason list');
        }

        if (($data['label'] === 'safe') !== ($reasons === [])) {
            throw RagEvalSetException::atLine($line, 'a safe item has no reasons and a risky item has at least one');
        }

        if (preg_match('/^mod-\d+$/', $data['id']) !== 1) {
            throw RagEvalSetException::atLine($line, '"id" must look like mod-001');
        }

        return new ModerationSetItem($data['id'], $data['title'], $data['make'], $data['model'], $data['year'], $data['price_eur'], $data['mileage_km'], $data['city'], $data['description'], $data['label'], $reasons, $data['kind']);
    }
}
