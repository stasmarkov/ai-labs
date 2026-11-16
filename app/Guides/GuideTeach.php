<?php

declare(strict_types=1);

namespace App\Guides;

final readonly class GuideTeach
{
    /**
     * @param  list<array{title: string, summary: string}>  $works
     * @param  list<array{step: string, detail: string, tab: string|null, doc: string|null}>  $build
     * @param  list<array{point: string, detail: string, tab: string}>  $watch
     * @param  list<array{domain: string, problem: string, how: string}>  $elsewhere
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $works,
        public array $build,
        public array $watch,
        public array $elsewhere,
    ) {}

    /**
     * @return array{key: string, title: string, works: list<array{title: string, summary: string}>, build: list<array{step: string, detail: string, tab: string|null, doc: string|null}>, watch: list<array{point: string, detail: string, tab: string}>, elsewhere: list<array{domain: string, problem: string, how: string}>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'works' => $this->works,
            'build' => $this->build,
            'watch' => $this->watch,
            'elsewhere' => $this->elsewhere,
        ];
    }
}
