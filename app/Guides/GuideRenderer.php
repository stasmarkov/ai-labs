<?php

declare(strict_types=1);

namespace App\Guides;

use App\DTOs\Guide;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

final readonly class GuideRenderer
{
    private const MARKDOWN = ['html_input' => 'escape', 'allow_unsafe_links' => false];

    /** Bump when build() changes shape, so cached payloads of the old shape are never read again. */
    private const VERSION = 2;

    /** Cached renders expire, so keys of edited guides do not pile up in a store without eviction. */
    private const TTL_SECONDS = 86400;

    public function __construct(
        private GuideRepository $guides,
        private SnippetResolver $snippets,
        private Cache $cache,
        private GuideFacts $facts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function render(string $key): array
    {
        $guide = $this->guides->find($key);

        $rendered = $this->cache->remember($this->cacheKey($guide), self::TTL_SECONDS, fn (): array => $this->build($guide));

        $rendered['numbers'] = array_map(fn (array $number): array => [
            'label' => $number['label'],
            'unit' => $number['unit'],
            'value' => $this->facts->number($number['file'], $number['path'], $number['pending']),
        ], $rendered['numbers']);
        $rendered['facts'] = $this->facts->live($guide->key);
        $rendered['table'] = $this->facts->table($guide->key);

        return $rendered;
    }

    /**
     * The "How it works" steps only: no snippets, numbers or facts, so a live page never depends on a
     * source file or a result file a guide refers to.
     *
     * @return list<array{title: string, html: string, event: string|null}>
     */
    public function steps(string $key): array
    {
        $guide = $this->guides->find($key);

        return $this->cache->remember(
            'guide-steps:'.self::VERSION.':'.$guide->key.':'.$guide->contentHash,
            self::TTL_SECONDS,
            fn (): array => array_map(fn ($step): array => [
                'title' => $step->title,
                'html' => $this->markdown($step->body),
                'event' => $step->event,
            ], $guide->works),
        );
    }

    public function cacheKey(Guide $guide): string
    {
        $mtimes = [];
        foreach (array_unique($guide->snippetReferences()) as $reference) {
            $path = $this->snippets->sourcePath($reference);
            $mtimes[$path] = filemtime($path);
        }
        ksort($mtimes);

        return 'guide:'.self::VERSION.':'.$guide->key.':'.sha1($guide->contentHash.json_encode($mtimes));
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Guide $guide): array
    {
        return [
            'key' => $guide->key,
            'title' => $guide->title,
            'summary' => $guide->summary,
            'kind' => $guide->kind,
            'planned' => $guide->planned,
            'works' => array_map(fn ($step): array => [
                'title' => $step->title,
                'html' => $this->markdown($step->body),
                'event' => $step->event,
                'snippet' => $this->snippet($step->snippet),
            ], $guide->works),
            'failures' => array_map(fn ($item): array => [
                'title' => $item->title,
                'html' => $this->markdown($item->body),
                'snippet' => $this->snippet($item->snippet),
            ], $guide->failures),
            'recipes' => array_map(fn ($recipe): array => [
                'title' => $recipe->title,
                'html' => $this->markdown($recipe->body),
                'snippet' => $this->snippet($recipe->snippet),
            ], $guide->recipes),
            'support' => array_map(fn ($row): array => [
                'name' => $row->name,
                'status' => $row->status,
                'html' => $this->markdown($row->note),
            ], $guide->support),
            'numbers' => array_map(fn ($number): array => [
                'label' => $number->label,
                'unit' => $number->unit,
                'file' => $number->file,
                'path' => $number->path,
                'pending' => $number->pending,
            ], $guide->numbers),
            'experiments' => array_map(fn ($experiment): array => [
                'title' => $experiment->title,
                'predictions' => $experiment->predictions,
                'answer' => [
                    'id' => $experiment->answer['id'],
                    'html' => $this->markdown($experiment->answer['body']),
                ],
                'page' => $experiment->page,
                'params' => $experiment->params,
            ], $guide->experiments),
            'plan' => array_map(fn (string $bullet): string => $this->markdown($bullet), $guide->plan),
            'related' => $guide->related,
        ];
    }

    private function markdown(string $body): string
    {
        return Str::markdown($body, self::MARKDOWN);
    }

    /**
     * @return array{file: string, startLine: int, endLine: int, code: string}|null
     */
    private function snippet(?string $reference): ?array
    {
        return $reference === null ? null : $this->snippets->resolve($reference);
    }
}
