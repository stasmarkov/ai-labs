<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\GuideFormatItem;
use App\DTOs\GuideFormatMeta;
use App\Enums\GuideFormatType;
use App\Guides\GuideFormatRepository;
use App\Guides\GuideRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class GuideFormatPageService
{
    public function __construct(
        private GuideFormatRepository $formats,
        private GuideRepository $guides,
    ) {}

    /**
     * The card the guides index shows for the guide format trial: the first guide, in key order, whose folder holds
     * a complete format set. Null when no guide has one.
     *
     * @return array{title: string, summary: string, href: string}|null
     */
    public function trial(): ?array
    {
        $key = array_find($this->formats->keys(), fn (string $key): bool => $this->guides->has($key));

        if ($key === null) {
            return null;
        }

        $names = array_map(fn (GuideFormatMeta $meta): string => $meta->name, $this->formats->compare($key)->entries);
        $last = array_pop($names);

        return [
            'title' => 'Guide format trial',
            'summary' => 'The "'.$this->guides->find($key)->title.'" guide taught five ways: '.implode(', ', $names).' and '.$last.'. Try them side by side.',
            'href' => route('ai.guides.formats', ['key' => $key], false),
        ];
    }

    /**
     * The compare page: the outcomes once, the rubric, and the baseline and each format with where it teaches each
     * outcome. Reads the format items only; their code is not resolved here.
     *
     * @return array{guide: array{key: string, title: string, href: string}, outcomes: list<array{id: string, text: string}>, rubric: list<array{name: string, meaning: string}>, entries: list<array{key: string, name: string, idea: string, time: string, href: string, outcomes: array<string, string>}>}
     */
    public function compare(string $key): array
    {
        $this->assertKnown($key);
        $compare = $this->formats->compare($key);
        $outcomes = array_keys($compare->outcomes);

        $entries = [$this->entry($compare->baseline, route('ai.guides.show', ['key' => $key], false), $compare->baseline->covers)];
        foreach ($compare->entries as $meta) {
            $type = GuideFormatType::from($meta->key);
            $entries[] = $this->entry(
                $meta,
                route('ai.guides.format', ['key' => $key, 'format' => $type->value], false),
                $this->coverage($this->formats->items($key, $type), $outcomes),
            );
        }

        return [
            'guide' => $this->guide($key),
            'outcomes' => array_map(fn (string $id, string $text): array => ['id' => $id, 'text' => $text], $outcomes, array_values($compare->outcomes)),
            'rubric' => $compare->rubric,
            'entries' => $entries,
        ];
    }

    /**
     * The props of one format page. Each snippet is sent once; an item points at it by its position in `snippets`.
     *
     * @return array{guide: array{key: string, title: string, href: string}, format: array{key: string, name: string, idea: string, time: string}, items: list<array<string, mixed>>, snippets: list<array{file: string, startLine: int, endLine: int, code: string}>, compareHref: string}
     */
    public function format(string $key, GuideFormatType $type): array
    {
        $this->assertKnown($key);
        $meta = array_find($this->formats->compare($key)->entries, fn (GuideFormatMeta $entry): bool => $entry->key === $type->value)
            ?? throw new NotFoundHttpException;
        $file = $this->formats->format($key, $type);
        $positions = array_flip(array_keys($file->snippets));

        return [
            'guide' => $this->guide($key),
            'format' => ['key' => $meta->key, 'name' => $meta->name, 'idea' => $meta->idea, 'time' => $meta->time],
            'items' => array_map(fn (GuideFormatItem $item): array => [
                ...$item->toArray(),
                'snippet' => $item->snippet === null ? null : $positions[$item->snippet],
            ], $file->items),
            'snippets' => array_values($file->snippets),
            'compareHref' => route('ai.guides.formats', ['key' => $key], false),
        ];
    }

    /**
     * The title of the first item that teaches each outcome, or an empty string when none does.
     *
     * @param  list<GuideFormatItem>  $items
     * @param  list<string>  $outcomes
     * @return array<string, string>
     */
    private function coverage(array $items, array $outcomes): array
    {
        $coverage = [];
        foreach ($outcomes as $outcome) {
            $item = array_find($items, fn (GuideFormatItem $item): bool => in_array($outcome, $item->outcomes, true));
            $coverage[$outcome] = $item === null ? '' : $item->title;
        }

        return $coverage;
    }

    /**
     * @param  array<string, string>  $outcomes
     * @return array{key: string, name: string, idea: string, time: string, href: string, outcomes: array<string, string>}
     */
    private function entry(GuideFormatMeta $meta, string $href, array $outcomes): array
    {
        return ['key' => $meta->key, 'name' => $meta->name, 'idea' => $meta->idea, 'time' => $meta->time, 'href' => $href, 'outcomes' => $outcomes];
    }

    /**
     * @return array{key: string, title: string, href: string}
     */
    private function guide(string $key): array
    {
        return [
            'key' => $key,
            'title' => $this->guides->find($key)->title,
            'href' => route('ai.guides.show', ['key' => $key], false),
        ];
    }

    private function assertKnown(string $key): void
    {
        if (! $this->formats->has($key) || ! $this->guides->has($key)) {
            throw new NotFoundHttpException;
        }
    }
}
