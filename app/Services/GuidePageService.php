<?php

declare(strict_types=1);

namespace App\Services;

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
     * The "How it works" steps shown beside a live page: title, rendered body and the event type that marks the step.
     *
     * @return list<array{title: string, html: string, event: string|null}>
     */
    public function howItWorks(string $key): array
    {
        return $this->renderer->steps($key);
    }

    /**
     * What a live page teaches below its form: how it works, how to build it, what to watch and the same pattern elsewhere.
     *
     * @return array{key: string, title: string, works: list<array{title: string, summary: string}>, build: list<array{step: string, detail: string, tab: string|null, doc: string|null}>, watch: list<array{point: string, detail: string, tab: string}>, elsewhere: list<array{domain: string, problem: string, how: string}>}
     */
    public function teach(string $key): array
    {
        return $this->guides->teach($key)->toArray();
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
        $guide['works'] = array_map(fn (array $step): array => array_diff_key($step, ['snippet' => true]), $guide['works']);
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
