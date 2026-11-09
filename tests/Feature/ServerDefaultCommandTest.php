<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/ddev-default-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/.ddev/servers/frankenphp/web-build', 0777, true);
    mkdir($this->root.'/bin');
    file_put_contents($this->root.'/.ddev/config.yaml', "name: interview\nwebserver_type: nginx-fpm\n");
    file_put_contents($this->root.'/.ddev/servers/frankenphp/config.frankenphp.yaml', "webserver_type: generic\n");
    foreach (['docker-compose.frankenphp.yaml', 'web-build/Caddyfile.frankenphp', 'web-build/Dockerfile.frankenphp', 'web-build/pre.Dockerfile.frankenphp'] as $file) {
        file_put_contents($this->root.'/.ddev/servers/frankenphp/'.$file, 'stash '.$file."\n");
    }
    $this->overlay = $this->root.'/.ddev/config.zz-server.yaml';
    $this->calls = $this->root.'/ddev-calls.log';

    $serverScript = base_path('.ddev/commands/host/server');
    file_put_contents($this->root.'/bin/ddev', <<<BASH
        #!/usr/bin/env bash
        echo "\$@" >> "\$DDEV_APPROOT/ddev-calls.log"
        case "\$1" in
          server) shift; exec bash "$serverScript" "\$@" ;;
          composer) mkdir -p "\$DDEV_APPROOT/vendor" && : > "\$DDEV_APPROOT/vendor/autoload.php" ;;
          npm) if [[ "\$2" == "run" ]]; then mkdir -p "\$DDEV_APPROOT/public/build" && : > "\$DDEV_APPROOT/public/build/manifest.json"; fi ;;
          artisan)
            if [[ "\$2" == "key:generate" ]]; then
              grep -v '^APP_KEY=' "\$DDEV_APPROOT/.env" > "\$DDEV_APPROOT/.env.tmp-gen" || true
              echo 'APP_KEY=base64:generated' >> "\$DDEV_APPROOT/.env.tmp-gen"
              mv "\$DDEV_APPROOT/.env.tmp-gen" "\$DDEV_APPROOT/.env"
            fi
            if [[ "\$2" == "mercure:keys" ]]; then
              if [[ "\$3" != "--force" ]] && grep -Eqs '^MERCURE_(PUBLISHER|SUBSCRIBER)_JWT_KEY=.+' "\$DDEV_APPROOT/.env" "\$DDEV_APPROOT/.ddev/.env.web"; then
                echo "already holds Mercure keys. Use --force to replace them." >&2
                exit 1
              fi
              printf 'MERCURE_PUBLISHER_JWT_KEY=a\\nMERCURE_SUBSCRIBER_JWT_KEY=b\\n' | tee -a "\$DDEV_APPROOT/.env" >> "\$DDEV_APPROOT/.ddev/.env.web"
            fi ;;
        esac
        exit 0
        BASH);
    chmod($this->root.'/bin/ddev', 0755);

    // A checkout that already has everything but the server and the keys; the fresh-clone tests remove what they need.
    mkdir($this->root.'/vendor');
    touch($this->root.'/vendor/autoload.php');
    mkdir($this->root.'/public/build', 0777, true);
    touch($this->root.'/public/build/manifest.json');
    file_put_contents($this->root.'/.env', "APP_KEY=base64:test\n");
    file_put_contents($this->root.'/.env.example', "APP_KEY=\nMERCURE_PUBLISHER_JWT_KEY=\n");

    $this->run = function (string $command, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('.ddev/commands/host/'.$command), ...$args],
            $this->root,
            ['DDEV_APPROOT' => $this->root, 'PATH' => $this->root.'/bin:'.getenv('PATH')],
        );
        $process->run();

        return $process;
    };
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-1 --default prints frankenphp without touching anything', function (): void {
    $process = ($this->run)('server', '--default');

    expect($process->getExitCode())->toBe(0)
        ->and(trim($process->getOutput()))->toBe('frankenphp')
        ->and(file_exists($this->overlay))->toBeFalse()
        ->and(file_exists($this->calls))->toBeFalse();
});

it('AC-1 default behaves as frankenphp', function (): void {
    $process = ($this->run)('server', 'default');

    expect($process->getExitCode())->toBe(0)
        ->and(file_get_contents($this->overlay))->toContain("# server: frankenphp\n")
        ->and(file_get_contents($this->calls))->toContain('restart')
        ->and(trim(($this->run)('server', '--current')->getOutput()))->toBe('frankenphp');
});

it('AC-1 no arguments print the active and default servers and the not-set-up hint', function (): void {
    $output = ($this->run)('server')->getOutput();

    expect($output)->toContain('Active: nginx-fpm (default: frankenphp)')
        ->and($output)->toContain('Supported:')
        ->and($output)->toContain('Not set up: run ddev setup');

    ($this->run)('server', 'default');
    $output = ($this->run)('server')->getOutput();

    expect($output)->toContain('Active: frankenphp (default: frankenphp)')
        ->and($output)->not->toContain('Not set up');
});

it('AC-2 setup switches to the default, creates missing keys, migrates and then reports already set up', function (): void {
    $first = ($this->run)('setup');
    $log = file_get_contents($this->calls);

    expect($first->getExitCode())->toBe(0)
        ->and($first->getOutput())->toContain('Set up: frankenphp')
        ->and($first->getOutput())->not->toContain('Already set up')
        ->and($log)->toContain('start')
        ->and($log)->toContain('server default')
        ->and($log)->toContain('artisan mercure:keys')
        ->and($log)->not->toContain('mercure:keys --force')
        ->and($log)->toContain('artisan migrate --force')
        ->and(file_get_contents($this->overlay))->toContain('# server: frankenphp');

    unlink($this->calls);
    $second = ($this->run)('setup');
    $log = file_get_contents($this->calls);

    expect($second->getExitCode())->toBe(0)
        ->and($second->getOutput())->toContain('Already set up: frankenphp')
        ->and($log)->not->toContain('mercure:keys')
        ->and($log)->not->toContain('server default')
        ->and($log)->not->toContain('restart')
        ->and($log)->toContain('artisan migrate --force');
});

it('AC-2 setup copies a complete pair into the file with an empty key without forcing', function (): void {
    ($this->run)('setup');
    $pair = "APP_KEY=base64:test\nMERCURE_PUBLISHER_JWT_KEY=a\nMERCURE_SUBSCRIBER_JWT_KEY=b\n";
    file_put_contents($this->root.'/.env', "APP_KEY=base64:test\nMERCURE_PUBLISHER_JWT_KEY=\nMERCURE_SUBSCRIBER_JWT_KEY=b\n");
    unlink($this->calls);

    $process = ($this->run)('setup');
    $log = file_get_contents($this->calls);

    expect($process->getExitCode())->toBe(0)
        ->and($log)->not->toContain('mercure:keys')
        ->and($log)->toContain('restart')
        ->and($log)->not->toContain('server default')
        ->and(file_get_contents($this->root.'/.env'))->toBe($pair)
        ->and(file_get_contents($this->root.'/.ddev/.env.web'))->toContain('MERCURE_PUBLISHER_JWT_KEY=a')
        ->and(file_get_contents($this->root.'/.ddev/.env.web'))->toContain('MERCURE_SUBSCRIBER_JWT_KEY=b');
});

it('AC-2 setup copies a complete pair from .env into a missing .ddev/.env.web', function (): void {
    file_put_contents($this->root.'/.env', "APP_KEY=base64:test\nAPP_NAME=x\nMERCURE_PUBLISHER_JWT_KEY=p\nMERCURE_SUBSCRIBER_JWT_KEY=s\n");

    $process = ($this->run)('setup');
    $log = file_get_contents($this->calls);

    expect($process->getExitCode())->toBe(0)
        ->and($log)->not->toContain('mercure:keys')
        ->and(file_get_contents($this->root.'/.ddev/.env.web'))->toBe("MERCURE_PUBLISHER_JWT_KEY=p\nMERCURE_SUBSCRIBER_JWT_KEY=s\n")
        ->and(file_get_contents($this->root.'/.env'))->toContain('APP_NAME=x');
});

it('AC-2 setup stops with exit 2 naming the missing keys when no file holds a complete pair', function (): void {
    file_put_contents($this->root.'/.env', "APP_KEY=base64:test\nMERCURE_PUBLISHER_JWT_KEY=\nMERCURE_SUBSCRIBER_JWT_KEY=b\n");
    file_put_contents($this->root.'/.ddev/.env.web', "MERCURE_PUBLISHER_JWT_KEY=a\n");

    $process = ($this->run)('setup');
    $log = file_get_contents($this->calls);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('MERCURE_PUBLISHER_JWT_KEY in .env')
        ->and($process->getErrorOutput())->toContain('MERCURE_SUBSCRIBER_JWT_KEY in .ddev/.env.web')
        ->and($process->getErrorOutput())->toContain('ddev artisan mercure:keys --force')
        ->and($log)->not->toContain('mercure:keys')
        ->and($log)->not->toContain('migrate');
});

it('MNC-1 MNC-3 other servers stay reachable and config.yaml is untouched', function (): void {
    $before = file_get_contents($this->root.'/.ddev/config.yaml');
    ($this->run)('server', 'default');
    $process = ($this->run)('server', 'apache-fpm');

    expect($process->getExitCode())->toBe(0)
        ->and(file_get_contents($this->overlay))->toContain('webserver_type: apache-fpm')
        ->and(file_get_contents($this->root.'/.ddev/config.yaml'))->toBe($before);
});

it('server --check default exits 0 only when overlay, activated files and no bench overlays match', function (): void {
    expect(($this->run)('server', '--check', 'default')->getExitCode())->toBe(1);

    ($this->run)('server', 'default');
    expect(($this->run)('server', '--check', 'default')->getExitCode())->toBe(0)
        ->and(($this->run)('server', '--check', 'frankenphp')->getExitCode())->toBe(0)
        ->and(($this->run)('server', '--check', 'nginx-fpm')->getExitCode())->toBe(1);

    unlink($this->root.'/.ddev/web-build/Caddyfile.frankenphp');
    expect(($this->run)('server', '--check', 'default')->getExitCode())->toBe(1);

    ($this->run)('server', 'default');
    file_put_contents($this->root.'/.ddev/config.zz-bench-x.yaml', "x\n");
    expect(($this->run)('server', '--check', 'default')->getExitCode())->toBe(1);
});

it('AC-2 setup does not report already set up when an activated file is missing', function (): void {
    ($this->run)('setup');
    unlink($this->root.'/.ddev/web-build/Caddyfile.frankenphp');
    unlink($this->calls);

    $process = ($this->run)('setup');

    expect($process->getOutput())->toContain('Set up: frankenphp')
        ->and($process->getOutput())->not->toContain('Already set up')
        ->and(file_get_contents($this->calls))->toContain('server default');
});

it('AC-2 setup brings up a fresh clone in order: .env, start, composer, key, keys, server, migrate, npm', function (): void {
    unlink($this->root.'/.env');
    unlink($this->root.'/vendor/autoload.php');
    unlink($this->root.'/public/build/manifest.json');

    $process = ($this->run)('setup');
    $calls = array_map('trim', file($this->calls));
    $position = fn (string $needle): int|false => array_search($needle, $calls, true);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Set up: frankenphp')
        ->and(file_get_contents($this->root.'/.env'))->toContain('APP_KEY=base64:generated')
        ->and($position('start'))->toBeLessThan($position('composer install'))
        ->and($position('composer install'))->toBeLessThan($position('artisan key:generate'))
        ->and($position('artisan key:generate'))->toBeLessThan($position('artisan mercure:keys'))
        ->and($position('artisan mercure:keys'))->toBeLessThan($position('server default'))
        ->and($position('server default'))->toBeLessThan($position('artisan migrate --force'))
        ->and($position('artisan migrate --force'))->toBeLessThan($position('npm install'))
        ->and($position('npm install'))->toBeLessThan($position('npm run build'))
        ->and(file_exists($this->root.'/vendor/autoload.php'))->toBeTrue()
        ->and(file_exists($this->root.'/public/build/manifest.json'))->toBeTrue();
});

it('AC-2 setup skips composer, key:generate and npm when they are not needed', function (): void {
    ($this->run)('setup');
    unlink($this->calls);

    ($this->run)('setup');
    $log = file_get_contents($this->calls);

    expect($log)->not->toContain('composer')
        ->and($log)->not->toContain('key:generate')
        ->and($log)->not->toContain('npm');
});

it('AC-2 setup runs each conditional step on its own', function (string $remove, string $expected, array $others): void {
    ($this->run)('setup');
    $remove === 'app_key' ? file_put_contents($this->root.'/.env', preg_replace('/^APP_KEY=.*\n/m', "APP_KEY=\n", file_get_contents($this->root.'/.env'))) : unlink($this->root.'/'.$remove);
    unlink($this->calls);

    $process = ($this->run)('setup');
    $log = file_get_contents($this->calls);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Set up: frankenphp')
        ->and($log)->toContain($expected);
    foreach ($others as $other) {
        expect($log)->not->toContain($other);
    }
})->with([
    'vendor missing' => ['vendor/autoload.php', 'composer install', ['key:generate', 'npm']],
    'build missing' => ['public/build/manifest.json', 'npm run build', ['composer', 'key:generate']],
    'app key empty' => ['app_key', 'artisan key:generate', ['composer', 'npm']],
]);

it('AC-2 setup copies .env.example when .env is missing', function (): void {
    unlink($this->root.'/.env');
    ($this->run)('setup');

    expect(file_get_contents($this->root.'/.env'))->toContain('APP_KEY=base64:generated');
});

it('AC-2 setup writes key files with mode 0600 even over an existing 0644 file', function (): void {
    file_put_contents($this->root.'/.env', "APP_KEY=base64:test\nMERCURE_PUBLISHER_JWT_KEY=p\nMERCURE_SUBSCRIBER_JWT_KEY=s\n");
    file_put_contents($this->root.'/.ddev/.env.web', "MERCURE_PUBLISHER_JWT_KEY=\nOTHER=1\n");
    chmod($this->root.'/.ddev/.env.web', 0644);

    $process = ($this->run)('setup');
    clearstatcache();

    expect($process->getExitCode())->toBe(0)
        ->and(decoct(fileperms($this->root.'/.ddev/.env.web') & 0777))->toBe('600')
        ->and(file_get_contents($this->root.'/.ddev/.env.web'))->toContain('OTHER=1')
        ->and(file_get_contents($this->root.'/.ddev/.env.web'))->toContain('MERCURE_SUBSCRIBER_JWT_KEY=s')
        ->and(glob($this->root.'/.ddev/.env.web.*', GLOB_BRACE))->toBe([]);
});

it('AC-2 setup leaves no temp file behind when the rename fails and never uses TMPDIR', function (): void {
    file_put_contents($this->root.'/.env', "APP_KEY=base64:test\nMERCURE_PUBLISHER_JWT_KEY=p\nMERCURE_SUBSCRIBER_JWT_KEY=s\n");
    $tmp = $this->root.'/tmpdir';
    mkdir($tmp);
    file_put_contents($this->root.'/bin/mv', "#!/usr/bin/env bash\nexit 1\n");
    chmod($this->root.'/bin/mv', 0755);

    $process = new Process(
        ['bash', base_path('.ddev/commands/host/setup')],
        $this->root,
        ['DDEV_APPROOT' => $this->root, 'TMPDIR' => $tmp, 'PATH' => $this->root.'/bin:'.getenv('PATH')],
    );
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and(scandir($tmp))->toBe(['.', '..']);
    $leftover = array_filter(scandir($this->root.'/.ddev'), fn (string $name): bool => str_starts_with($name, '.env.web.'));
    expect($leftover)->toBe([]);
});
