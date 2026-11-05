<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/mercure-keys-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/.ddev', 0777, true);
    $this->envFile = $this->dir.'/.env';
    $this->ddevFile = $this->dir.'/.ddev/.env.web';
    $this->run = fn (array $options = []) => $this->artisan('mercure:keys', ['--env-file' => $this->envFile, '--ddev-file' => $this->ddevFile] + $options);
});

afterEach(function (): void {
    array_map('unlink', array_filter([$this->envFile, $this->ddevFile], 'file_exists'));
    @rmdir($this->dir.'/.ddev');
    @rmdir($this->dir);
});

function parseKeys(string $file): array
{
    preg_match_all('/^(MERCURE_(?:PUBLISHER|SUBSCRIBER)_JWT_KEY)=(.*)$/m', file_get_contents($file), $m);

    return array_combine($m[1], $m[2]);
}

test('NFR-1 the key files are written 0600, also when they already existed with looser modes', function () {
    touch($this->envFile);
    chmod($this->envFile, 0644);
    $old = umask(0);

    try {
        ($this->run)()->assertSuccessful();
    } finally {
        umask($old);
    }

    clearstatcache();
    expect(fileperms($this->envFile) & 0777)->toBe(0600)
        ->and(fileperms($this->ddevFile) & 0777)->toBe(0600)
        ->and(glob($this->dir.'/.mercure-keys-*'))->toBeEmpty();
});

test('NFR-1 the ddev env file is gitignored', function () {
    $root = base_path();
    $check = new Process(['git', 'check-ignore', '-q', '.ddev/.env.web'], $root);
    $check->run();

    expect($check->getExitCode())->toBe(0)
        ->and(file_get_contents($root.'/.gitignore'))->toContain("/.ddev/.env.web\n");
});

test('NFR-1 no mercure key value appears in a tracked file', function () {
    $keys = [];
    foreach (['.env', '.ddev/.env.web'] as $file) {
        if (is_file(base_path($file))) {
            $keys = array_merge($keys, array_filter(parseKeys(base_path($file))));
        }
    }

    $tracked = new Process(['git', 'ls-files', '-z'], base_path());
    $tracked->mustRun();

    foreach (array_filter(explode("\0", $tracked->getOutput())) as $path) {
        $full = base_path($path);
        if (! is_file($full) || filesize($full) > 5_000_000) {
            continue;
        }
        $contents = file_get_contents($full);

        expect(preg_match('/MERCURE_(PUBLISHER|SUBSCRIBER)_JWT_KEY=[0-9a-f]{32,}/', $contents))->toBe(0, "{$path} holds a Mercure key");
        foreach ($keys as $value) {
            expect(str_contains($contents, $value))->toBeFalse("{$path} contains a generated key");
        }
    }
});

test('NFR-1 .env.example names the keys without values', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^MERCURE_PUBLISHER_JWT_KEY=$/m')
        ->and($example)->toMatch('/^MERCURE_SUBSCRIBER_JWT_KEY=$/m');
});

test('AC-3 the stash Caddyfile enables the hub on the router block with env keys', function () {
    $caddy = file_get_contents(base_path('.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp'));

    expect($caddy)->toContain('(mercure_hub)')
        ->and($caddy)->toContain('publisher_jwt {env.MERCURE_PUBLISHER_JWT_KEY}')
        ->and($caddy)->toContain('subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY}')
        ->and($caddy)->toContain('anonymous')
        ->and($caddy)->toContain('transport bolt')
        ->and($caddy)->toContain('cors_origins https://interview.ddev.site')
        ->and(substr_count($caddy, 'import mercure_hub'))->toBe(1)
        ->and($caddy)->toContain('reverse_proxy /.well-known/mercure* localhost:80');
});

test('MNC-3 mercure:keys writes two distinct 256-bit keys to both files', function () {
    ($this->run)()->assertSuccessful();

    $env = parseKeys($this->envFile);
    $ddev = parseKeys($this->ddevFile);

    expect($env)->toHaveCount(2)->and($ddev)->toBe($env)
        ->and($env['MERCURE_PUBLISHER_JWT_KEY'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($env['MERCURE_SUBSCRIBER_JWT_KEY'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($env['MERCURE_PUBLISHER_JWT_KEY'])->not->toBe($env['MERCURE_SUBSCRIBER_JWT_KEY']);
});

test('MNC-3 mercure:keys keeps other .env lines and refuses to overwrite without --force', function () {
    file_put_contents($this->envFile, "APP_NAME=Keep\nMERCURE_PUBLISHER_JWT_KEY=\n");

    ($this->run)()->assertSuccessful();
    $first = parseKeys($this->envFile);

    ($this->run)()->assertFailed();
    expect(parseKeys($this->envFile))->toBe($first)
        ->and(file_get_contents($this->envFile))->toContain("APP_NAME=Keep\n");

    ($this->run)(['--force' => true])->assertSuccessful();
    expect(parseKeys($this->envFile))->not->toBe($first)
        ->and(substr_count(file_get_contents($this->envFile), 'MERCURE_PUBLISHER_JWT_KEY='))->toBe(1);
});
