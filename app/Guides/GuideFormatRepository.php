<?php

declare(strict_types=1);

namespace App\Guides;

use App\DTOs\GuideFormatCompare;
use App\DTOs\GuideFormatFile;
use App\DTOs\GuideFormatItem;
use App\DTOs\GuideFormatMeta;
use App\Enums\GuideFormatType;

/**
 * Loads and validates the guide format files: one folder per guide key holding `_compare.php` and one file per format.
 * A folder is a format set only when all six files are there. Items are read without their code (enough for the
 * compare page); a format page also resolves each code reference once and checks the tour notes against it.
 * Bound per request (scoped), like the guide repository.
 */
final class GuideFormatRepository
{
    public const OUTCOMES = ['LO-1', 'LO-2', 'LO-3', 'LO-4', 'LO-5'];

    private const COMPARE = '_compare';

    private const RUBRIC_ROWS = 4;

    /** @var array<string, bool> */
    private array $complete = [];

    /** @var array<string, GuideFormatCompare> */
    private array $compares = [];

    /** @var array<string, list<GuideFormatItem>> */
    private array $items = [];

    /** @var array<string, GuideFormatFile> */
    private array $files = [];

    public function __construct(
        private readonly string $directory,
        private readonly SnippetResolver $snippets,
    ) {}

    /**
     * Whether the key has a complete format set: `_compare.php` and one file per format. Checked once per request.
     */
    public function has(string $key): bool
    {
        if (preg_match('/^[a-z0-9-]+$/', $key) !== 1) {
            return false;
        }

        return $this->complete[$key] ??= $this->missingFile($key) === null;
    }

    public function compare(string $key): GuideFormatCompare
    {
        return $this->compares[$key] ??= $this->loadCompare($key);
    }

    /**
     * The validated items of one format, code references left unresolved.
     *
     * @return list<GuideFormatItem>
     */
    public function items(string $key, GuideFormatType $type): array
    {
        return $this->items[$key.'/'.$type->value] ??= $this->loadItems($key, $type);
    }

    /**
     * The validated items of one format with every code reference resolved once.
     */
    public function format(string $key, GuideFormatType $type): GuideFormatFile
    {
        return $this->files[$key.'/'.$type->value] ??= $this->loadFormat($key, $type);
    }

    private function loadCompare(string $key): GuideFormatCompare
    {
        $this->assertComplete($key);
        $file = $key.'/'.self::COMPARE.'.php';
        $data = $this->read($key, self::COMPARE);

        $declared = $this->map($file, $data, 'outcomes');
        $outcomes = [];
        foreach (self::OUTCOMES as $id) {
            $outcomes[$id] = $this->string($file, $declared, $id, "outcomes.{$id}");
        }

        $rubric = $this->list($file, $data, 'rubric');
        if (count($rubric) !== self::RUBRIC_ROWS) {
            throw $this->error($file, 'rubric', 'must have '.self::RUBRIC_ROWS.' criteria, not '.count($rubric));
        }

        $baseline = $this->map($file, $data, 'baseline');
        $covers = $this->map($file, $baseline, 'covers', 'baseline.covers');
        $entries = $this->map($file, $data, 'formats');

        return new GuideFormatCompare(
            outcomes: $outcomes,
            rubric: array_map(fn (int $index): array => [
                'name' => $this->string($file, $rubric[$index], 'name', "rubric.{$index}.name"),
                'meaning' => $this->string($file, $rubric[$index], 'meaning', "rubric.{$index}.meaning"),
            ], array_keys($rubric)),
            baseline: new GuideFormatMeta(
                'baseline',
                $this->string($file, $baseline, 'name', 'baseline.name'),
                $this->string($file, $baseline, 'idea', 'baseline.idea'),
                $this->string($file, $baseline, 'time', 'baseline.time'),
                array_combine(self::OUTCOMES, array_map(fn (string $id): string => $this->string($file, $covers, $id, "baseline.covers.{$id}"), self::OUTCOMES)),
            ),
            entries: array_map(function (GuideFormatType $type) use ($file, $entries): GuideFormatMeta {
                $path = "formats.{$type->value}";
                $entry = $this->map($file, $entries, $type->value, $path);

                return new GuideFormatMeta(
                    $type->value,
                    $this->string($file, $entry, 'name', "{$path}.name"),
                    $this->string($file, $entry, 'idea', "{$path}.idea"),
                    $this->string($file, $entry, 'time', "{$path}.time"),
                );
            }, GuideFormatType::cases()),
        );
    }

    /**
     * @return list<GuideFormatItem>
     */
    private function loadItems(string $key, GuideFormatType $type): array
    {
        $this->assertComplete($key);
        $file = $key.'/'.$type->value.'.php';
        $rows = $this->list($file, $this->read($key, $type->value), $type->itemsField());

        if ($rows === []) {
            throw $this->error($file, $type->itemsField(), 'must not be empty');
        }

        $items = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $path = $type->itemsField().'.'.$index;
            $id = $this->string($file, $row, 'id', "{$path}.id");

            if (preg_match('/^[a-z0-9-]+$/', $id) !== 1 || isset($seen[$id])) {
                throw $this->error($file, "{$path}.id", 'must be a unique slug');
            }
            $seen[$id] = true;

            $fields = [];
            foreach ($type->fields() as $field => $fieldType) {
                $fields[$field] = $this->field($file, $row, $field, $fieldType, "{$path}.{$field}", $fields);
            }

            $items[] = new GuideFormatItem(
                id: $id,
                title: $this->string($file, $row, 'title', "{$path}.title"),
                outcomes: $this->outcomes($file, $row, "{$path}.outcomes"),
                snippet: $type->snippetIsOptional() && ! isset($row['snippet']) ? null : $this->string($file, $row, 'snippet', "{$path}.snippet"),
                fields: $fields,
            );
        }

        return $items;
    }

    private function loadFormat(string $key, GuideFormatType $type): GuideFormatFile
    {
        $file = $key.'/'.$type->value.'.php';
        $items = $this->items($key, $type);
        $snippets = [];

        foreach ($items as $index => $item) {
            if ($item->snippet === null) {
                continue;
            }

            $path = $type->itemsField().'.'.$index;
            $snippets[$item->snippet] ??= $this->snippet($file, $item->snippet, "{$path}.snippet");

            if ($type === GuideFormatType::Tour) {
                $this->assertNotesOnCode($file, $item->fields['notes'], $snippets[$item->snippet]['code'], "{$path}.notes");
            }
        }

        return new GuideFormatFile($type, $items, $snippets);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $read  The fields read so far, for the types that point at another field.
     */
    private function field(string $file, array $row, string $field, string $type, string $path, array $read): mixed
    {
        if (str_starts_with($type, 'index:')) {
            $value = $row[$field] ?? null;
            $count = count($read[substr($type, 6)]);
            if (! is_int($value) || $value < 0 || $value >= $count) {
                throw $this->error($file, $path, 'must be a position between 0 and '.($count - 1));
            }

            return $value;
        }

        return match ($type) {
            'string' => $this->string($file, $row, $field, $path),
            'options', 'clues' => $this->strings($file, $row, $field, $path, 2),
            'causes' => $this->causes($file, $row, $field, $path),
            'notes' => $this->notes($file, $row, $field, $path),
            default => throw new InvalidGuideException("Unknown field type [{$type}]."),
        };
    }

    /**
     * @return array{file: string, startLine: int, endLine: int, code: string}
     */
    private function snippet(string $file, string $reference, string $path): array
    {
        try {
            return $this->snippets->resolve($reference);
        } catch (InvalidGuideException $exception) {
            throw $this->error($file, $path, $exception->getMessage());
        }
    }

    /**
     * Each note must stay inside the snippet and start on a line of code, not on a blank, comment or brace-only line,
     * so the note sits next to the code it describes.
     *
     * @param  list<array{from: int, to: int, text: string}>  $notes
     */
    private function assertNotesOnCode(string $file, array $notes, string $code, string $path): void
    {
        $lines = explode("\n", html_entity_decode($code, ENT_QUOTES));

        foreach ($notes as $index => $note) {
            if ($note['to'] > count($lines)) {
                throw $this->error($file, "{$path}.{$index}", 'must target lines 1 to '.count($lines).' of the snippet');
            }

            $first = trim($lines[$note['from'] - 1]);
            if (preg_match('#^(?:$|/\*|\*|//|[{}()\[\];,]+$)#', $first) === 1) {
                throw $this->error($file, "{$path}.{$index}", "must start on a line of code, not [{$first}]");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function outcomes(string $file, array $row, string $path): array
    {
        $outcomes = $this->strings($file, $row, 'outcomes', $path, 1);

        foreach ($outcomes as $outcome) {
            if (! in_array($outcome, self::OUTCOMES, true)) {
                throw $this->error($file, $path, "has unknown learning outcome [{$outcome}]");
            }
        }

        return $outcomes;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function strings(string $file, array $row, string $field, string $path, int $min): array
    {
        $values = $row[$field] ?? null;

        if (! is_array($values) || ! array_is_list($values) || count($values) < $min) {
            throw $this->error($file, $path, "must be a list of at least {$min} texts");
        }

        foreach ($values as $index => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw $this->error($file, "{$path}.{$index}", 'must be a non-empty text');
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{text: string, why: string}>
     */
    private function causes(string $file, array $row, string $field, string $path): array
    {
        $causes = $this->list($file, $row, $field, $path);
        if (count($causes) < 2) {
            throw $this->error($file, $path, 'must hold at least 2 causes');
        }

        return array_map(fn (int $index): array => [
            'text' => $this->string($file, $causes[$index], 'text', "{$path}.{$index}.text"),
            'why' => $this->string($file, $causes[$index], 'why', "{$path}.{$index}.why"),
        ], array_keys($causes));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{from: int, to: int, text: string}>
     */
    private function notes(string $file, array $row, string $field, string $path): array
    {
        $notes = $this->list($file, $row, $field, $path);
        if ($notes === []) {
            throw $this->error($file, $path, 'must hold at least 1 annotation');
        }

        return array_map(function (int $index) use ($file, $notes, $path): array {
            $note = $notes[$index];
            $from = $note['from'] ?? null;
            $to = $note['to'] ?? null;

            if (! is_int($from) || ! is_int($to) || $from < 1 || $to < $from) {
                throw $this->error($file, "{$path}.{$index}", 'must target snippet lines, from 1 and from <= to');
            }

            return ['from' => $from, 'to' => $to, 'text' => $this->string($file, $note, 'text', "{$path}.{$index}.text")];
        }, array_keys($notes));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $key, string $name): array
    {
        $data = require $this->directory.'/'.$key.'/'.$name.'.php';

        if (! is_array($data)) {
            throw new InvalidGuideException("Guide format file [{$key}/{$name}.php] must return an array.");
        }

        return $data;
    }

    private function missingFile(string $key): ?string
    {
        foreach ([self::COMPARE, ...array_column(GuideFormatType::cases(), 'value')] as $name) {
            if (! is_file($this->directory.'/'.$key.'/'.$name.'.php')) {
                return $key.'/'.$name.'.php';
            }
        }

        return null;
    }

    private function assertComplete(string $key): void
    {
        if ($this->has($key)) {
            return;
        }

        if (preg_match('/^[a-z0-9-]+$/', $key) !== 1 || ! is_dir($this->directory.'/'.$key)) {
            throw new InvalidGuideException("Unknown guide format set [{$key}].");
        }

        throw new InvalidGuideException("Guide format set [{$key}] is missing the file [{$this->missingFile($key)}].");
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function map(string $file, array $data, string $field, ?string $path = null): array
    {
        $value = $data[$field] ?? null;

        if (! is_array($value)) {
            throw $this->error($file, $path ?? $field, 'is required');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function list(string $file, array $data, string $field, ?string $path = null): array
    {
        $value = $data[$field] ?? null;

        if (! is_array($value) || ! array_is_list($value) || array_filter($value, fn (mixed $row): bool => ! is_array($row)) !== []) {
            throw $this->error($file, $path ?? $field, 'is required and must be a list of rows');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(string $file, array $data, string $field, string $path): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw $this->error($file, $path, 'is required');
        }

        return $value;
    }

    private function error(string $file, string $path, string $problem): InvalidGuideException
    {
        return new InvalidGuideException("Guide format file [{$file}] field [{$path}] ".rtrim($problem, '.').'.');
    }
}
