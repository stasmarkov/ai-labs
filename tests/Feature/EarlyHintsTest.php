<?php

declare(strict_types=1);

use App\Contracts\EarlyHintSender;
use App\Http\Middleware\SendEarlyHints;
use App\Models\Dealer;
use App\Models\Listing;
use App\Support\PhpEarlyHintSender;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;

function recordingSender(bool $available, ?Throwable $failure = null): object
{
    return new class($available, $failure) implements EarlyHintSender
    {
        /** @var list<list<string>> */
        public array $sent = [];

        public function __construct(private readonly bool $available, private readonly ?Throwable $failure) {}

        public function isAvailable(): bool
        {
            return $this->available;
        }

        public function send(array $links): void
        {
            $this->sent[] = $links;

            if ($this->failure !== null) {
                throw $this->failure;
            }
        }
    };
}

function earlyHintDetailUrl(): string
{
    return '/listings/'.Listing::factory()->for(Dealer::factory())->create()->id;
}

it('AC-2 sends the manifest links as a 103 when available and on', function (): void {
    config(['performance.early_hints' => true]);
    $sender = recordingSender(true);
    $this->app->instance(EarlyHintSender::class, $sender);

    $html = $this->get(earlyHintDetailUrl())->assertOk()->getContent();

    expect($sender->sent)->toHaveCount(1);
    $joined = implode(',', $sender->sent[0]);

    preg_match_all('/<link rel="stylesheet" href="([^"]+)"/', $html, $css);
    preg_match_all('/<script type="module" src="([^"]+)"/', $html, $js);

    foreach ($css[1] as $url) {
        expect($joined)->toContain('<'.$url.'>; rel="preload"; as="style"');
    }
    foreach ($js[1] as $url) {
        expect($joined)->toContain('<'.$url.'>; rel="modulepreload"; as="script"');
    }
})->skip(fn (): bool => ! file_exists(public_path('build/manifest.json')), 'requires the Vite build');

it('AC-2 does not send when the setting is off', function (): void {
    config(['performance.early_hints' => false]);
    $sender = recordingSender(true);
    $this->app->instance(EarlyHintSender::class, $sender);

    $this->get(earlyHintDetailUrl())->assertOk();

    expect($sender->sent)->toBeEmpty();
});

it('AC-2 does not send when the server cannot send hints', function (): void {
    config(['performance.early_hints' => true]);
    $sender = recordingSender(false);
    $this->app->instance(EarlyHintSender::class, $sender);

    $this->get(earlyHintDetailUrl())->assertOk();

    expect($sender->sent)->toBeEmpty();
});

it('NFR-2 never throws when the sender fails', function (): void {
    config(['performance.early_hints' => true]);
    $sender = recordingSender(true, new RuntimeException('boom'));
    $this->app->instance(EarlyHintSender::class, $sender);

    $this->get(earlyHintDetailUrl())->assertOk();

    expect($sender->sent)->toHaveCount(1);
})->skip(fn (): bool => ! file_exists(public_path('build/manifest.json')), 'requires the Vite build');

it('NFR-2 never throws when the build manifest is missing', function (): void {
    config(['performance.early_hints' => true, 'performance.early_hint_entries' => ['resources/css/missing.css']]);
    $sender = recordingSender(true);
    $this->app->instance(EarlyHintSender::class, $sender);

    $this->get(earlyHintDetailUrl())->assertOk();

    expect($sender->sent)->toBeEmpty();
});

it('AC-2 leaves the final response identical with hints on, off and unavailable', function (): void {
    $url = earlyHintDetailUrl();

    config(['performance.early_hints' => false]);
    $off = $this->get($url)->assertOk();

    config(['performance.early_hints' => true]);
    $this->app->instance(EarlyHintSender::class, recordingSender(true));
    $on = $this->get($url)->assertOk();

    $this->app->instance(EarlyHintSender::class, recordingSender(false));
    $unavailable = $this->get($url)->assertOk();

    expect($on->getContent())->toBe($off->getContent())
        ->and($unavailable->getContent())->toBe($off->getContent())
        ->and($on->headers->all('link'))->toBe($off->headers->all('link'))
        ->and($unavailable->headers->all('link'))->toBe($off->headers->all('link'));
})->skip(fn (): bool => ! file_exists(public_path('build/manifest.json')), 'requires the Vite build');

it('AC-2 the real sender is unavailable without headers_send and the response is unchanged', function (): void {
    config(['performance.early_hints' => true]);
    $url = earlyHintDetailUrl();

    config(['performance.early_hints' => false]);
    $off = $this->get($url)->assertOk();

    config(['performance.early_hints' => true]);
    $sender = new PhpEarlyHintSender(hasHeadersSend: false, headersAlreadySent: false, server: []);
    $this->app->instance(EarlyHintSender::class, $sender);
    $on = $this->get($url)->assertOk();

    expect($sender->isAvailable())->toBeFalse()
        ->and($on->getContent())->toBe($off->getContent())
        ->and($on->headers->all('link'))->toBe($off->headers->all('link'));
});

it('AC-2 the real sender is unavailable when FRANKENPHP_WORKER is set', function (): void {
    $worker = new PhpEarlyHintSender(hasHeadersSend: true, headersAlreadySent: false, server: ['FRANKENPHP_WORKER' => '1']);
    $classic = new PhpEarlyHintSender(hasHeadersSend: true, headersAlreadySent: false, server: []);
    $sent = new PhpEarlyHintSender(hasHeadersSend: true, headersAlreadySent: true, server: []);

    expect($worker->isAvailable())->toBeFalse()
        ->and($classic->isAvailable())->toBeTrue()
        ->and($sent->isAvailable())->toBeFalse();
});

it('AC-2 the real sender is unavailable when the process environment carries a worker marker', function (): void {
    $workerEnv = new PhpEarlyHintSender(hasHeadersSend: true, headersAlreadySent: false, server: [], environment: ['FRANKENPHP_WORKER' => '1']);
    $octaneEnv = new PhpEarlyHintSender(hasHeadersSend: true, headersAlreadySent: false, server: [], environment: ['LARAVEL_OCTANE' => '1']);
    $classic = new PhpEarlyHintSender(hasHeadersSend: true, headersAlreadySent: false, server: [], environment: []);

    expect($workerEnv->isAvailable())->toBeFalse()
        ->and($octaneEnv->isAvailable())->toBeFalse()
        ->and($classic->isAvailable())->toBeTrue();
});

it('AC-2 the real sender reads getenv for the worker marker', function (): void {
    putenv('LARAVEL_OCTANE=1');

    try {
        $sender = new PhpEarlyHintSender(hasHeadersSend: true, headersAlreadySent: false, server: []);

        expect($sender->isAvailable())->toBeFalse();
    } finally {
        putenv('LARAVEL_OCTANE');
    }
});

it('AC-2 the middleware link set equals Vite preloaded assets for a fixture manifest with entry css and nested imports', function (): void {
    $directory = 'build-fixture-parity';
    File::ensureDirectoryExists(public_path($directory));
    file_put_contents(public_path($directory.'/manifest.json'), json_encode([
        'resources/css/app.css' => ['file' => 'assets/a.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
        'resources/js/app.js' => ['file' => 'assets/a.js', 'src' => 'resources/js/app.js', 'isEntry' => true, 'imports' => ['_x.js'], 'css' => ['assets/own.css']],
        'own.css' => ['file' => 'assets/own.css', 'src' => 'own.css'],
        '_x.js' => ['file' => 'assets/x.js', 'imports' => ['_y.js'], 'css' => ['assets/x.css']],
        '_y.js' => ['file' => 'assets/y.js', 'imports' => ['_x.js'], 'css' => ['assets/y.css']],
        'x.css' => ['file' => 'assets/x.css', 'src' => 'x.css'],
        'y.css' => ['file' => 'assets/y.css', 'src' => 'y.css'],
    ]));

    try {
        config(['performance.early_hints' => true, 'performance.early_hint_build_directory' => $directory]);
        $sender = recordingSender(true);
        (new SendEarlyHints($sender))->handle(request(), fn () => response('ok'));

        $vite = new Illuminate\Foundation\Vite;
        $tags = (string) $vite(config('performance.early_hint_entries'), $directory);
        $expected = collect($vite->preloadedAssets())
            ->map(fn (array $attributes, string $url): string => "<{$url}>; ".implode('; ', $attributes))
            ->values()->all();
    } finally {
        File::deleteDirectory(public_path($directory));
    }

    expect($tags)->toContain('rel="preload"')
        ->and($sender->sent)->toHaveCount(1)
        ->and($sender->sent[0])->toHaveCount(7)
        ->and($sender->sent[0])->toBe($expected);
});

it('NFR-2 the container default sender is unavailable in the CLI and the request still succeeds', function (): void {
    config(['performance.early_hints' => true]);

    expect($this->app->make(EarlyHintSender::class))->toBeInstanceOf(PhpEarlyHintSender::class)
        ->and($this->app->make(EarlyHintSender::class)->isAvailable())->toBeFalse();

    $this->get(earlyHintDetailUrl())->assertOk();
});

it('AC-2 sends nothing when the Vite dev server is running', function (): void {
    $hot = tempnam(sys_get_temp_dir(), 'hot');
    file_put_contents($hot, 'http://localhost:5173');
    Vite::useHotFile($hot);
    config(['performance.early_hints' => true]);
    $sender = recordingSender(true);
    $this->app->instance(EarlyHintSender::class, $sender);

    $this->get(earlyHintDetailUrl())->assertOk();
    unlink($hot);

    expect($sender->sent)->toBeEmpty();
});

it('NFR-2 sends nothing and reports nothing when there is no manifest', function (): void {
    Exceptions::fake();
    config(['performance.early_hints' => true, 'performance.early_hint_build_directory' => 'build-absent']);
    $sender = recordingSender(true);

    (new SendEarlyHints($sender))->handle(request(), fn () => response('ok'));

    expect($sender->sent)->toBeEmpty();
    Exceptions::assertNothingReported();
});

it('AC-2 the hint URLs equal the final Link header for a manifest with imports and css', function (): void {
    $directory = 'build-fixture';
    File::ensureDirectoryExists(public_path($directory));
    file_put_contents(public_path($directory.'/manifest.json'), json_encode([
        'resources/css/app.css' => ['file' => 'assets/app-aaa.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
        'resources/js/app.js' => [
            'file' => 'assets/app-bbb.js', 'src' => 'resources/js/app.js', 'isEntry' => true,
            'imports' => ['_shared-ccc.js'], 'css' => ['assets/app-fff.css'],
        ],
        'app-fff.css' => ['file' => 'assets/app-fff.css', 'src' => 'app-fff.css'],
        '_shared-ccc.js' => ['file' => 'assets/shared-ccc.js', 'imports' => ['_deep-ddd.js'], 'css' => ['assets/shared-eee.css']],
        '_deep-ddd.js' => ['file' => 'assets/deep-ddd.js'],
        'shared-eee.css' => ['file' => 'assets/shared-eee.css', 'src' => 'shared-eee.css'],
    ]));

    try {
        config(['performance.early_hints' => true, 'performance.early_hint_build_directory' => $directory]);
        Vite::useBuildDirectory($directory);
        $sender = recordingSender(true);
        $this->app->instance(EarlyHintSender::class, $sender);

        $response = $this->get(earlyHintDetailUrl())->assertOk();
    } finally {
        File::deleteDirectory(public_path($directory));
    }

    $urls = fn (array $links): array => collect($links)
        ->map(fn (string $link): string => explode('>', ltrim(trim($link), '<'))[0])
        ->sort()->values()->all();
    $header = collect(explode(',', implode(',', $response->headers->all('link'))))->map(fn ($l) => trim($l))->all();

    $viteLinks = collect(Vite::preloadedAssets())
        ->map(fn (array $attributes, string $url): string => "<{$url}>; ".implode('; ', $attributes))
        ->values()->all();

    expect($sender->sent)->toHaveCount(1)
        ->and($sender->sent[0])->toHaveCount(6)
        ->and($urls($sender->sent[0]))->toBe($urls($header))
        ->and(collect($sender->sent[0])->sort()->values()->all())->toBe(collect($viteLinks)->sort()->values()->all());
});

it('AC-2 the config entries equal the layout vite list', function (): void {
    $layout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));
    preg_match('/@vite\(\[(.*?)\]\)/s', $layout, $match);
    preg_match_all("/'([^']+)'/", $match[1], $entries);

    expect(config('performance.early_hint_entries'))->toBe($entries[1]);
});
