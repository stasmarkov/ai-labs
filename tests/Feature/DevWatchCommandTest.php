<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/dev-watch-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/.ddev/servers/frankenphp-octane', 0777, true);
    mkdir($this->root.'/bootstrap/cache', 0777, true);
    copy(
        base_path('.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml'),
        $this->root.'/.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml',
    );
    file_put_contents($this->root.'/.ddev/config.yaml', "name: interview\nwebserver_type: generic\n");
    file_put_contents($this->root.'/.env', "APP_NAME=lab\n");
    file_put_contents($this->root.'/bin/ddev', <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
if [[ "$*" == "server --current" ]]; then
  echo "$STUB_SERVER"
fi
BASH);
    chmod($this->root.'/bin/ddev', 0755);
    $this->overlay = $this->root.'/.ddev/config.zzz-dev-watch.yaml';

    $this->devWatch = function (string $server, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('.ddev/commands/host/dev-watch'), ...$args],
            $this->root,
            [
                'DDEV_APPROOT' => $this->root,
                'STUB_SERVER' => $server,
                'STUB_LOG' => $this->root.'/calls.log',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->run();

        return $process;
    };
    $this->ddevCalls = fn (): string => file_exists($this->root.'/calls.log') ? file_get_contents($this->root.'/calls.log') : '';
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-5 dev-watch on in frankenphp-worker writes the watch globs and off restores', function (): void {
    $on = ($this->devWatch)('frankenphp-worker', 'on');

    expect($on->getExitCode())->toBe(0, $on->getErrorOutput())
        ->and($on->getOutput())->toBe("dev-watch: on (server: frankenphp-worker)\n")
        ->and(file_get_contents($this->overlay))->toContain(
            'web_environment:',
            'WORKER_WATCH=watch /var/www/html/app/**/*.php\nwatch /var/www/html/config/**/*.php',
            'watch /var/www/html/routes/**/*.php',
            'watch /var/www/html/resources/**/*.php',
            'watch /var/www/html/bootstrap/**/*.php',
            'watch /var/www/html/composer.lock',
            'watch /var/www/html/.env',
        )
        ->and(file_get_contents($this->overlay))->not->toContain('vendor', 'storage', 'node_modules', 'public/build', 'override_config')
        ->and(($this->devWatch)('frankenphp-worker', 'status')->getOutput())->toBe("dev-watch: on (server: frankenphp-worker)\n");

    $off = ($this->devWatch)('frankenphp-worker', 'off');

    expect($off->getExitCode())->toBe(0, $off->getErrorOutput())
        ->and(file_exists($this->overlay))->toBeFalse()
        ->and(substr_count(($this->ddevCalls)(), 'ddev restart'))->toBe(2)
        ->and(($this->devWatch)('frankenphp-worker', 'status')->getOutput())->toBe("dev-watch: off (server: frankenphp-worker)\n");
});

it('AC-5 dev-watch on in frankenphp-octane adds --watch to the daemon and off restores', function (): void {
    $stash = file_get_contents($this->root.'/.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml');
    $on = ($this->devWatch)('frankenphp-octane', 'on');

    expect($on->getExitCode())->toBe(0, $on->getErrorOutput())
        ->and(file_get_contents($this->overlay))->toContain('override_config: true', 'octane:frankenphp --watch --host=0.0.0.0', '--workers=4', 'frankenphp-octane')
        ->and(substr_count(file_get_contents($this->overlay), 'override_config'))->toBe(1)
        ->and($stash)->not->toContain('--watch');

    expect(($this->devWatch)('frankenphp-octane', 'off')->getExitCode())->toBe(0)
        ->and(file_exists($this->overlay))->toBeFalse();
});

it('F1 dev-watch on refuses on Octane while a bench daemon overlay exists, without writing or restarting', function (): void {
    $bench = $this->root.'/.ddev/config.zzz-bench-concurrency.yaml';
    file_put_contents($bench, "override_config: true\nweb_extra_daemons:\n  - name: \"frankenphp-octane\"\n    command: \"php artisan octane:frankenphp --workers=8 --max-requests=1000000\"\n");

    $on = ($this->devWatch)('frankenphp-octane', 'on');

    expect($on->getExitCode())->toBe(2)
        ->and($on->getErrorOutput())->toContain('bench overlay', 'ddev server frankenphp-octane')
        ->and(file_exists($this->overlay))->toBeFalse()
        ->and(($this->ddevCalls)())->not->toContain('ddev restart')
        ->and(($this->devWatch)('frankenphp-octane', 'status')->getOutput())->toBe("dev-watch: off (server: frankenphp-octane)\n");
});

it('AC-5 the dev-watch overlay sorts after the server overlay, so its override_config replaces the daemon instead of duplicating it', function (): void {
    $names = ['config.zz-server.yaml', basename($this->overlay)];
    $sorted = $names;
    sort($sorted, SORT_STRING);

    expect($sorted)->toBe($names)
        ->and(file_get_contents(base_path('.gitignore')))->toContain('.ddev/'.basename($this->overlay));
});

it('F14 the dev-watch overlay path is one shared name, and bench_overlays lists bench overlays only', function (): void {
    $servers = file_get_contents(base_path('bench/lib/servers.sh'));
    $server = file_get_contents(base_path('.ddev/commands/host/server'));
    $devWatch = file_get_contents(base_path('.ddev/commands/host/dev-watch'));
    $benchOverlays = substr($server, strpos($server, 'bench_overlays() {'));
    $benchOverlays = substr($benchOverlays, 0, strpos($benchOverlays, "\n}\n"));

    expect($servers)->toContain('DEV_WATCH_OVERLAY_NAME=config.zzz-dev-watch.yaml')
        ->and(substr_count($server.$devWatch, 'config.zzz-dev-watch.yaml'))->toBe(0)
        ->and($benchOverlays)->not->toContain('dev-watch')
        ->and($server)->toContain('dev_watch_overlay');
});

it('AC-5 dev-watch on classic writes no overlay and does not restart', function (): void {
    $on = ($this->devWatch)('frankenphp', 'on');

    expect($on->getExitCode())->toBe(0)
        ->and($on->getOutput())->toContain('classic mode reloads on every request; nothing to watch')
        ->and(file_exists($this->overlay))->toBeFalse()
        ->and(($this->ddevCalls)())->not->toContain('ddev restart');
});

it('AC-5 dev-watch rejects an unknown action without side effects', function (): void {
    $process = ($this->devWatch)('frankenphp-worker', 'sideways');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Usage')
        ->and(file_exists($this->overlay))->toBeFalse()
        ->and(($this->ddevCalls)())->not->toContain('ddev restart');
});

it('AC-5 dev-watch warns when config is cached', function (): void {
    file_put_contents($this->root.'/bootstrap/cache/config.php', '<?php return [];');

    $on = ($this->devWatch)('frankenphp-worker', 'on');

    expect($on->getExitCode())->toBe(0)
        ->and($on->getErrorOutput())->toContain('cached', 'optimize:clear');
});

it('MNC-2 dev-watch never touches config.yaml or .env', function (): void {
    $config = file_get_contents($this->root.'/.ddev/config.yaml');
    $env = file_get_contents($this->root.'/.env');

    foreach (['frankenphp-worker', 'frankenphp-octane'] as $server) {
        ($this->devWatch)($server, 'on');
        ($this->devWatch)($server, 'off');
    }

    expect(file_get_contents($this->root.'/.ddev/config.yaml'))->toBe($config)
        ->and(file_get_contents($this->root.'/.env'))->toBe($env)
        ->and(file_get_contents(base_path('.gitignore')))->toContain('.ddev/config.zzz-dev-watch.yaml')
        ->and(file_get_contents(base_path('bench/lib/servers.sh')))->toContain('config.zzz-dev-watch.yaml');
});

it('AC-1 the worker Caddyfile expands WORKER_WATCH inside the worker block and watches nothing by default', function (): void {
    $caddyfile = file_get_contents(base_path('.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp'));

    $worker = substr($caddyfile, strpos($caddyfile, "\t\tworker {"));
    $worker = substr($worker, 0, strpos($worker, "\n\t\t}\n"));

    expect($worker)->toContain("\n\t\t\t{\$WORKER_WATCH}")
        ->and($caddyfile)->not->toContain('{$WORKER_WATCH:')
        ->and(preg_match('/^\s*watch\b/m', $caddyfile))->toBe(0);
});

it('F3 both modes watch bootstrap, composer.lock and .env, so provider and dependency edits reload too', function (): void {
    ($this->devWatch)('frankenphp-worker', 'on');
    $worker = file_get_contents($this->overlay);

    foreach (['bootstrap/**/*.php', 'composer.lock', '.env'] as $path) {
        expect($worker)->toContain('watch /var/www/html/'.$path)
            ->and(config('octane.watch'))->toContain($path);
    }
});

it('AC-2 the octane watch paths match the worker globs and the stash daemon does not watch', function (): void {
    $paths = config('octane.watch');
    $stash = file_get_contents(base_path('.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml'));

    expect($paths)->toBe(['app/**/*.php', 'config/**/*.php', 'routes/**/*.php', 'resources/**/*.php', 'bootstrap/**/*.php', 'composer.lock', '.env'])
        ->and($stash)->not->toContain('--watch');
});
