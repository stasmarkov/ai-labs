<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\StanfordCarsClass;
use Database\Seeders\Catalog;

/**
 * Parses a Stanford class name ("Ford Fiesta Sedan 2012") against the catalog; a name without a catalog make,
 * model and year is not a match.
 */
final readonly class StanfordCarsClassParser
{
    /**
     * @var array<string, string>
     */
    private const MAKE_ALIASES = [
        'Mercedes' => 'Mercedes-Benz',
        'VW' => 'Volkswagen',
    ];

    public function parse(string $name): ?StanfordCarsClass
    {
        $name = trim($name);

        if (preg_match('/^(.*\S)\s+(\d{4})$/', $name, $parts) !== 1) {
            return null;
        }

        [, $rest, $year] = $parts;
        $make = $this->matchMake($rest);

        if ($make === null) {
            return null;
        }

        [$makeName, $rest] = $make;
        $model = $this->matchModel($makeName, $rest);

        if ($model === null) {
            return null;
        }

        return new StanfordCarsClass($name, $makeName, $model[0], $model[1], (int) $year);
    }

    /**
     * @return array{string, string}|null the catalog make and the rest of the name
     */
    private function matchMake(string $name): ?array
    {
        $candidates = [...array_combine(array_keys(Catalog::MODELS_BY_MAKE), array_keys(Catalog::MODELS_BY_MAKE)), ...self::MAKE_ALIASES];
        uksort($candidates, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($candidates as $spelling => $make) {
            $rest = $this->stripWord($name, $spelling);

            if ($rest !== null) {
                return [$make, $rest];
            }
        }

        return null;
    }

    /**
     * @return array{string, string}|null the catalog model and the body words
     */
    private function matchModel(string $make, string $rest): ?array
    {
        $models = Catalog::MODELS_BY_MAKE[$make];
        usort($models, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($models as $model) {
            $body = $this->stripWord($rest, $model);

            if ($body !== null) {
                return [$model, $body];
            }
        }

        return null;
    }

    /**
     * The text after a leading word, when the word ends on a word boundary.
     */
    private function stripWord(string $text, string $word): ?string
    {
        if (preg_match('/^'.preg_quote($word, '/').'(?:\s+(.*))?$/i', $text, $parts) !== 1) {
            return null;
        }

        return trim($parts[1] ?? '');
    }
}
