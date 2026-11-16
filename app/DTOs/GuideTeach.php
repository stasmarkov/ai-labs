<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Contracts\Support\Arrayable;

/**
 * What a live lab page teaches below its form, read from the guide file so the page and its guide never drift apart.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class GuideTeach implements Arrayable
{
    /**
     * @param  list<array{title: string, summary: string, event: string|null}>  $works
     * @param  list<array{step: string, detail: string, tab: string|null, doc: string|null}>  $build
     * @param  list<array{point: string, detail: string, tab: string}>  $watch
     * @param  list<array{domain: string, problem: string, how: string}>  $elsewhere
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $href,
        public array $works,
        public array $build,
        public array $watch,
        public array $elsewhere,
    ) {}

    /**
     * The build, watch and elsewhere rows of a guide as arrays: the one mapping both the guide page and a lab page use.
     *
     * @return array{build: list<array{step: string, detail: string, tab: string|null, doc: string|null}>, watch: list<array{point: string, detail: string, tab: string}>, elsewhere: list<array{domain: string, problem: string, how: string}>}
     */
    public static function rows(Guide $guide): array
    {
        return [
            'build' => array_map(fn (GuideBuildStep $row): array => $row->toArray(), $guide->build),
            'watch' => array_map(fn (GuideWatchPoint $row): array => $row->toArray(), $guide->watch),
            'elsewhere' => array_map(fn (GuideElsewhere $row): array => $row->toArray(), $guide->elsewhere),
        ];
    }

    /**
     * @return array{key: string, title: string, href: string, works: list<array{title: string, summary: string, event: string|null}>, build: list<array{step: string, detail: string, tab: string|null, doc: string|null}>, watch: list<array{point: string, detail: string, tab: string}>, elsewhere: list<array{domain: string, problem: string, how: string}>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'href' => $this->href,
            'works' => $this->works,
            'build' => $this->build,
            'watch' => $this->watch,
            'elsewhere' => $this->elsewhere,
        ];
    }
}
