<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One line of bench/docs-queries.txt: the kind of query, its text and the files any of which counts as a hit ("source:path").
 */
final readonly class DocsBenchQuery
{
    public const IDENTIFIER = 'identifier';

    public const MEANING = 'meaning';

    /**
     * @param  list<string>  $expected
     */
    public function __construct(
        public string $type,
        public string $query,
        public array $expected,
    ) {}

    /**
     * @return self|null null when the line is not three tab-separated columns with a known type
     */
    public static function fromLine(string $line): ?self
    {
        $columns = explode("\t", rtrim($line, "\r\n"));

        if (count($columns) !== 3 || ! in_array($columns[0], [self::IDENTIFIER, self::MEANING], true) || trim($columns[1]) === '') {
            return null;
        }

        $expected = array_values(array_filter(array_map('trim', explode('|', $columns[2])), fn (string $file): bool => $file !== ''));

        return $expected === [] ? null : new self($columns[0], trim($columns[1]), $expected);
    }
}
