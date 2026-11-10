<?php

declare(strict_types=1);

use App\Guides\GuideFacts;
use App\Guides\GuideRenderer;
use App\Guides\GuideRepository;
use App\Guides\InvalidGuideException;
use App\Guides\SnippetResolver;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function guideFixture(string $title, string $body, string $snippet): string
{
    return "<?php\nreturn ".var_export([
        'kind' => 'capability',
        'title' => $title,
        'summary' => 'Sum',
        'works' => [['title' => 'Step', 'body' => $body, 'event' => 'token', 'snippet' => $snippet]],
        'numbers' => [['label' => 'Later', 'pending' => true]],
        'experiments' => [['title' => 'E', 'predictions' => [['id' => 'a', 'text' => 'P'], ['id' => 'b', 'text' => 'Q']], 'answer' => ['id' => 'b', 'body' => 'Because **so**'], 'page' => 'describe', 'params' => ['q' => 'a']]],
        'related' => ['other'],
    ], true).";\n";
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/guides-'.uniqid();
    mkdir($this->root.'/app', 0777, true);
    mkdir($this->root.'/resources/guides', 0777, true);
    file_put_contents($this->root.'/app/code.php', "<?php\n\$a = 1;\n");
    file_put_contents($this->root.'/resources/guides/demo.php', guideFixture('Demo', 'Hello **world**', 'app/code.php'));
    $this->renderer = fn () => new GuideRenderer(
        new GuideRepository($this->root.'/resources/guides'),
        new SnippetResolver($this->root),
        $this->cache = new Repository(new ArrayStore),
        new GuideFacts($this->root.'/bench/results'),
    );
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->root)));

it('AC-3 loads a guide into DTOs and renders markdown with the snippet', function (): void {
    $guide = ($this->renderer)()->render('demo');

    expect($guide['works'][0]['html'])->toContain('<strong>world</strong>')
        ->and($guide['works'][0]['snippet']['file'])->toBe('app/code.php')
        ->and($guide['numbers'][0])->toBe(['label' => 'Later', 'unit' => null, 'value' => 'not measured yet'])
        ->and($guide['experiments'][0]['params'])->toBe(['q' => 'a'])
        ->and($guide['experiments'][0]['answer']['html'])->toContain('<strong>so</strong>');
});

it('NFR-3 escapes raw HTML and drops unsafe links in guide markdown', function (): void {
    file_put_contents($this->root.'/resources/guides/demo.php', guideFixture(
        'Demo',
        '<script>alert(1)</script> [x](javascript:void)',
        'app/code.php',
    ));
    $html = ($this->renderer)()->render('demo')['works'][0]['html'];

    expect($html)->not->toContain('<script>')->toContain('&lt;script&gt;')
        ->and($html)->not->toContain('href="javascript');
});

it('AC-3 changes the cache key when a referenced source file changes', function (): void {
    $renderer = ($this->renderer)();
    $guide = (new GuideRepository($this->root.'/resources/guides'))->find('demo');
    $before = $renderer->cacheKey($guide);

    touch($this->root.'/app/code.php', time() + 100);
    clearstatcache();

    expect($renderer->cacheKey($guide))->not->toBe($before);
});

it('AC-3 changes the cache key when the guide file changes and serves a hit otherwise', function (): void {
    $renderer = ($this->renderer)();
    $first = $renderer->render('demo');
    file_put_contents($this->root.'/app/code.php', "<?php\n\$a = 1;\n");
    touch($this->root.'/app/code.php', filemtime($this->root.'/app/code.php'));
    expect($renderer->render('demo'))->toBe($first);

    file_put_contents($this->root.'/resources/guides/demo.php', guideFixture('Changed', 'Hi', 'app/code.php'));
    expect($renderer->render('demo')['title'])->toBe('Changed');
});

it('rejects an invalid guide and an unknown key', function (): void {
    file_put_contents($this->root.'/resources/guides/bad.php', "<?php\nreturn ['title' => 'x'];\n");
    $repository = new GuideRepository($this->root.'/resources/guides');

    expect($repository->has('../demo'))->toBeFalse()
        ->and($repository->keys())->toBe(['bad', 'demo']);
    expect(fn () => $repository->find('bad'))->toThrow(InvalidGuideException::class);
    expect(fn () => $repository->find('nope'))->toThrow(InvalidGuideException::class);
});

it('AC-6 rejects an experiment answer id that is not among its predictions', function (): void {
    $data = require $this->root.'/resources/guides/demo.php';
    $data['experiments'][0]['answer']['id'] = 'zzz';
    file_put_contents($this->root.'/resources/guides/demo.php', '<?php return '.var_export($data, true).';');

    expect(fn () => ($this->renderer)()->render('demo'))->toThrow(InvalidGuideException::class, 'zzz');
});

it('AC-6 rejects an experiment with fewer than two predictions', function (): void {
    $data = require $this->root.'/resources/guides/demo.php';
    $data['experiments'][0]['predictions'] = [['id' => 'a', 'text' => 'P']];
    file_put_contents($this->root.'/resources/guides/demo.php', '<?php return '.var_export($data, true).';');

    expect(fn () => ($this->renderer)()->render('demo'))->toThrow(InvalidGuideException::class);
});

it('AC-4 rejects a planned guide with no plan and renders plan bullets as markdown', function (): void {
    $data = require $this->root.'/resources/guides/demo.php';
    $data['kind'] = 'planned';
    file_put_contents($this->root.'/resources/guides/demo.php', '<?php return '.var_export($data, true).';');

    expect(fn () => ($this->renderer)()->render('demo'))->toThrow(InvalidGuideException::class, 'plan');

    $data['plan'] = ['Do **this** first'];
    file_put_contents($this->root.'/resources/guides/demo.php', '<?php return '.var_export($data, true).';');

    expect(($this->renderer)()->render('demo')['plan'][0])->toContain('<strong>this</strong>');
});

it('MNC-2 serves the live-page steps even when a snippet or number reference is broken', function (): void {
    file_put_contents($this->root.'/resources/guides/demo.php', guideFixture('Demo', 'Hello **world**', 'app/missing.php'));

    expect(fn () => ($this->renderer)()->render('demo'))->toThrow(InvalidGuideException::class);

    $steps = ($this->renderer)()->steps('demo');

    expect($steps)->toBe([['title' => 'Step', 'html' => "<p>Hello <strong>world</strong></p>\n", 'event' => 'token']]);
});

it('NFR-1 caches renders under a versioned key that expires', function (): void {
    ($this->renderer)()->render('demo');
    ($this->renderer)()->steps('demo');

    $keys = array_keys((fn (): array => $this->storage)->call($this->cache->getStore()));

    expect($keys)->each->toMatch('/^guide(-steps)?:\d+:demo:/');
});
