<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\GuideStep;
use App\DTOs\GuideTeach;
use App\Guides\GuideRenderer;
use App\Guides\GuideRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class GuidePageService
{
    private const TABS = [
        'works' => 'How it works',
        'implementation' => 'Implementation',
        'modify' => 'Modify',
        'support' => 'Support',
        'numbers' => 'Numbers',
        'experiments' => 'Experiments',
        'plan' => 'Plan',
    ];

    private const TEACH_TABS = [
        'build' => 'Build it yourself',
        'watch' => 'Watch out for',
        'elsewhere' => 'Elsewhere',
    ];

    /** A "How it works" step on a live page is cut to a first sentence of at most this length. */
    private const TEACH_SUMMARY_LENGTH = 240;

    public function __construct(
        private GuideRepository $guides,
        private GuideRenderer $renderer,
    ) {}

    /**
     * @return array{guides: list<array{key: string, title: string, summary: string, planned: bool, group: string}>}
     */
    public function index(): array
    {
        $guides = [];
        foreach ($this->guides->keys() as $key) {
            $guide = $this->guides->find($key);
            $guides[] = [
                'key' => $guide->key,
                'title' => $guide->title,
                'summary' => $guide->summary,
                'planned' => $guide->planned,
                'group' => $guide->kind === 'foundation' ? 'foundation' : 'capability',
            ];
        }

        return ['guides' => $guides];
    }

    /**
     * What a live page teaches below its form: every "How it works" step cut to its first sentence (with the event that
     * marks it during a run), how to build it, what to watch and the same pattern elsewhere. The guide file is the one
     * source; the repository already refused a teaching guide with more than six works steps, so nothing is cut here.
     */
    public function teach(string $key): GuideTeach
    {
        $guide = $this->guides->find($key);
        $rows = GuideTeach::rows($guide);

        return new GuideTeach(
            key: $guide->key,
            title: $guide->title,
            href: route('ai.guides.show', ['key' => $guide->key], false),
            works: array_map(fn (GuideStep $step): array => [
                'title' => $step->title,
                'summary' => $this->firstSentence($step->body),
                'event' => $step->event,
            ], $guide->works),
            build: $rows['build'],
            watch: $rows['watch'],
            elsewhere: $rows['elsewhere'],
        );
    }

    /**
     * The first sentence of a markdown body as plain text. A sentence over the limit is cut at the last word boundary
     * inside it, or hard at the limit when there is none, and always keeps some text before the ellipsis.
     */
    private function firstSentence(string $body): string
    {
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $body);
        $plain = trim((string) preg_replace('/\s+/', ' ', str_replace(['`', '**', '*'], '', $text)));

        $sentence = preg_match('/^.+?[.!?](?=\s|$)/u', $plain, $match) === 1 ? $match[0] : $plain;

        if (mb_strlen($sentence) <= self::TEACH_SUMMARY_LENGTH) {
            return $sentence;
        }

        $cut = mb_substr($sentence, 0, self::TEACH_SUMMARY_LENGTH - 1);
        $space = mb_strrpos($cut, ' ');
        $atWord = $space === false || $space === 0 ? '' : rtrim(mb_substr($cut, 0, $space), ' ,;:');

        return ($atWord === '' ? $cut : $atWord).'…';
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $key): array
    {
        if (! $this->guides->has($key)) {
            throw new NotFoundHttpException;
        }

        $guide = $this->renderer->render($key);

        $guide['implementation'] = array_values(array_filter($guide['works'], fn (array $step): bool => $step['snippet'] !== null));
        $guide['works'] = array_map(fn (array $step): array => array_diff_key($step, ['snippet' => true, 'moreSnippets' => true]), $guide['works']);
        $guide['experiments'] = array_map(fn (array $experiment): array => array_diff_key($experiment, ['page' => true, 'params' => true]) + [
            'href' => $this->experimentHref($experiment['page'], $experiment['params']),
        ], $guide['experiments']);
        $guide['related'] = array_values(array_map(
            fn (string $related): array => ['key' => $related, 'title' => $this->guides->find($related)->title],
            array_filter($guide['related'], fn (string $related): bool => $this->guides->has($related)),
        ));
        $guide['tabs'] = $this->tabs($guide);
        $guide['teachTabs'] = $this->teachTabs($guide);

        return $guide;
    }

    /**
     * @param  array<string, mixed>  $guide
     * @return list<array{key: string, label: string}>
     */
    private function tabs(array $guide): array
    {
        $contents = [
            'works' => $guide['works'],
            'implementation' => $guide['implementation'],
            'modify' => $guide['recipes'],
            'support' => [...$guide['support'], ...$guide['failures']],
            'numbers' => $guide['numbers'],
            'experiments' => $guide['experiments'],
            'plan' => $guide['plan'],
        ];

        $tabs = [];
        foreach (self::TABS as $tab => $label) {
            if ($contents[$tab] !== []) {
                $tabs[] = ['key' => $tab, 'label' => $label];
            }
        }

        return $tabs;
    }

    /**
     * The teach sections come after the six tabs, in a prop of their own so the six stay as they were.
     *
     * @param  array<string, mixed>  $guide
     * @return list<array{key: string, label: string}>
     */
    private function teachTabs(array $guide): array
    {
        $tabs = [];
        foreach (self::TEACH_TABS as $tab => $label) {
            if ($guide[$tab] !== []) {
                $tabs[] = ['key' => $tab, 'label' => $label];
            }
        }

        return $tabs;
    }

    /**
     * @param  array<array-key, string>  $params
     */
    private function experimentHref(string $page, array $params): string
    {
        $href = route($page, absolute: false);

        return isset($params['q']) ? $href.'?'.http_build_query(['q' => $params['q']]) : $href;
    }
}
