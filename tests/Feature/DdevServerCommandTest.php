<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/ddev-server-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/.ddev', 0777, true);
    mkdir($this->root.'/bin');
    file_put_contents($this->root.'/.ddev/config.yaml', "name: interview\nwebserver_type: nginx-fpm\n");
    file_put_contents($this->root.'/bin/ddev', "#!/usr/bin/env bash\necho \"\$@\" >> \"\$DDEV_APPROOT/ddev-calls.log\"\n[[ -f \"\$DDEV_APPROOT/ddev-fail\" ]] && exit 7\nexit 0\n");
    chmod($this->root.'/bin/ddev', 0755);
    $this->overlay = $this->root.'/.ddev/config.zz-server.yaml';
    $this->configBefore = file_get_contents($this->root.'/.ddev/config.yaml');

    $this->server = function (string ...$args): Process {
        $process = new Process(
            ['bash', base_path('.ddev/commands/host/server'), ...$args],
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

it('AC-1 apache-fpm writes the overlay and restarts', function (): void {
    $process = ($this->server)('apache-fpm');

    expect($process->getExitCode())->toBe(0)
        ->and(file_get_contents($this->overlay))->toContain('webserver_type: apache-fpm')
        ->and(file_get_contents($this->root.'/ddev-calls.log'))->toContain('restart')
        ->and($process->getOutput())->toContain('apache-fpm');
});

it('AC-2 nginx-fpm switches back', function (): void {
    ($this->server)('apache-fpm');
    $process = ($this->server)('nginx-fpm');

    expect($process->getExitCode())->toBe(0)
        ->and(file_get_contents($this->overlay))->toContain('webserver_type: nginx-fpm')
        ->and($process->getOutput())->toContain('nginx-fpm');
});

it('AC-3 no argument prints the active and supported servers without restarting', function (): void {
    $process = ($this->server)();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('nginx-fpm')
        ->and($process->getOutput())->toContain('apache-fpm')
        ->and(file_exists($this->overlay))->toBeFalse()
        ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();

    ($this->server)('apache-fpm');
    expect(($this->server)()->getOutput())->toContain('Active: apache-fpm');
});

it('AC-4 invalid argument exits non-zero, prints supported values and changes nothing', function (): void {
    $process = ($this->server)('foo');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('nginx-fpm apache-fpm')
        ->and(file_exists($this->overlay))->toBeFalse()
        ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();
});

it('AC-4 invalid argument leaves an existing overlay untouched', function (): void {
    ($this->server)('apache-fpm');
    $before = file_get_contents($this->overlay);
    unlink($this->root.'/ddev-calls.log');

    $process = ($this->server)('foo');

    expect($process->getExitCode())->toBe(1)
        ->and(file_get_contents($this->overlay))->toBe($before)
        ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();
});

it('AC-1 overlay names the server so later servers can share a webserver_type', function (): void {
    ($this->server)('apache-fpm');

    expect(file_get_contents($this->overlay))->toContain("# server: apache-fpm\n");

    file_put_contents($this->overlay, "# server: frankenphp\nwebserver_type: generic\n");

    expect(trim(($this->server)('--current')->getOutput()))->toBe('frankenphp');
});

it('AC-4 restart failure restores the overlay and tries to restart the previous server', function (): void {
    touch($this->root.'/ddev-fail');

    $process = ($this->server)('apache-fpm');

    expect($process->getExitCode())->toBe(1)
        ->and(substr_count(file_get_contents($this->root.'/ddev-calls.log'), 'restart'))->toBe(2)
        ->and($process->getErrorOutput())->toContain("run 'ddev restart'");
});

it('AC-5 overlay is gitignored', function (): void {
    $ignored = new Process(['grep', '-qxF', '.ddev/config.zz-server.yaml', base_path('.gitignore')]);
    $ignored->run();

    expect($ignored->getExitCode())->toBe(0);
});

it('MNC-1 never modifies config.yaml', function (): void {
    ($this->server)('apache-fpm');
    ($this->server)('foo');

    expect(file_get_contents($this->root.'/.ddev/config.yaml'))->toBe($this->configBefore);
});

it('AC-3 --current prints only the active server', function (): void {
    $process = ($this->server)('--current');

    expect($process->getExitCode())->toBe(0)
        ->and(trim($process->getOutput()))->toBe('nginx-fpm')
        ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();

    ($this->server)('apache-fpm');

    expect(trim(($this->server)('--current')->getOutput()))->toBe('apache-fpm');
});

it('AC-2 switching to the already active server is skipped', function (): void {
    $process = ($this->server)('nginx-fpm');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('already active')
        ->and(file_exists($this->overlay))->toBeFalse()
        ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();

    ($this->server)('apache-fpm');
    unlink($this->root.'/ddev-calls.log');
    $again = ($this->server)('apache-fpm');

    expect($again->getExitCode())->toBe(0)
        ->and($again->getOutput())->toContain('already active')
        ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();
});

it('AC-1 restart failure removes the new overlay when there was none', function (): void {
    touch($this->root.'/ddev-fail');

    $process = ($this->server)('apache-fpm');

    expect($process->getExitCode())->toBe(1)
        ->and(file_exists($this->overlay))->toBeFalse();
});

it('AC-1 restart failure restores the previous overlay', function (): void {
    ($this->server)('apache-fpm');
    $previous = file_get_contents($this->overlay);
    touch($this->root.'/ddev-fail');

    $process = ($this->server)('nginx-fpm');

    expect($process->getExitCode())->toBe(1)
        ->and(file_get_contents($this->overlay))->toBe($previous);
});
