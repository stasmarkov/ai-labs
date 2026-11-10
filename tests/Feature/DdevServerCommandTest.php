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
    $this->stash = $this->root.'/.ddev/servers/frankenphp';
    $this->activated = [
        'docker-compose.frankenphp.yaml',
        'web-build/Caddyfile.frankenphp',
        'web-build/Dockerfile.frankenphp',
        'web-build/pre.Dockerfile.frankenphp',
    ];
    mkdir($this->stash.'/web-build', 0777, true);
    file_put_contents($this->stash.'/config.frankenphp.yaml', "#ddev-generated\nwebserver_type: generic\n");
    file_put_contents($this->stash.'/VERSION', "ddev/ddev-frankenphp v1.3.0\n");
    foreach ($this->activated as $file) {
        file_put_contents($this->stash.'/'.$file, 'stash '.$file."\n");
    }
    $this->configBefore = file_get_contents($this->root.'/.ddev/config.yaml');

    $this->server = function (string ...$args): Process {
        $process = new Process(
            ['bash', base_path('.ddev/commands/host/server'), ...$args],
            $this->root,
            ['DDEV_HOSTNAME' => false, 'DDEV_APPROOT' => $this->root, 'PATH' => $this->root.'/bin:'.getenv('PATH')],
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

it('AC-5 frankenphp activated files are gitignored', function (): void {
    $stash = base_path('.ddev/servers/frankenphp');
    $ignored = file(base_path('.gitignore'), FILE_IGNORE_NEW_LINES);

    foreach ([
        'config.frankenphp.yaml',
        'docker-compose.frankenphp.yaml',
        'web-build/Caddyfile.frankenphp',
        'web-build/Dockerfile.frankenphp',
        'web-build/pre.Dockerfile.frankenphp',
    ] as $file) {
        expect(is_file($stash.'/'.$file))->toBeTrue();
    }

    foreach ([
        '.ddev/docker-compose.frankenphp.yaml',
        '.ddev/web-build/Caddyfile.frankenphp',
        '.ddev/web-build/Dockerfile.frankenphp',
        '.ddev/web-build/pre.Dockerfile.frankenphp',
    ] as $activated) {
        expect($ignored)->toContain($activated);
    }

    expect(trim(file_get_contents($stash.'/VERSION')))->toBe('ddev/ddev-frankenphp v1.3.0')
        ->and(file_exists(base_path('.ddev/addon-metadata/frankenphp')))->toBeFalse();
});

it('AC-1 frankenphp activates the stash and writes its overlay', function (): void {
    $process = ($this->server)('frankenphp');

    expect($process->getExitCode())->toBe(0)
        ->and(file_get_contents($this->overlay))->toBe("# server: frankenphp\n#ddev-generated\nwebserver_type: generic\n")
        ->and(file_get_contents($this->root.'/ddev-calls.log'))->toContain('restart')
        ->and(trim(($this->server)('--current')->getOutput()))->toBe('frankenphp');

    foreach ($this->activated as $file) {
        expect(file_get_contents($this->root.'/.ddev/'.$file))->toBe('stash '.$file."\n");
    }

    expect(file_exists($this->root.'/.ddev/config.frankenphp.yaml'))->toBeFalse()
        ->and(file_exists($this->root.'/.ddev/VERSION'))->toBeFalse();
});

it('AC-2 switching away from frankenphp removes the activated files', function (): void {
    ($this->server)('frankenphp');
    $process = ($this->server)('apache-fpm');

    expect($process->getExitCode())->toBe(0)
        ->and(file_get_contents($this->overlay))->toContain('webserver_type: apache-fpm');

    foreach ($this->activated as $file) {
        expect(file_exists($this->root.'/.ddev/'.$file))->toBeFalse();
    }

    expect(file_exists($this->stash.'/config.frankenphp.yaml'))->toBeTrue();
});

it('AC-3 frankenphp is a supported server', function (): void {
    $listed = ($this->server)();

    expect($listed->getOutput())->toContain('Supported: nginx-fpm apache-fpm frankenphp');

    ($this->server)('frankenphp');

    expect(trim(($this->server)('--current')->getOutput()))->toBe('frankenphp')
        ->and(($this->server)()->getOutput())->toContain('Active: frankenphp');
});

it('AC-4 failed switch to frankenphp restores the previous file set', function (): void {
    touch($this->root.'/ddev-fail');

    $process = ($this->server)('frankenphp');

    expect($process->getExitCode())->toBe(1)
        ->and(file_exists($this->overlay))->toBeFalse();

    foreach ($this->activated as $file) {
        expect(file_exists($this->root.'/.ddev/'.$file))->toBeFalse();
    }
});

it('AC-4 failed switch away from frankenphp puts the activated files back', function (): void {
    ($this->server)('frankenphp');
    $previous = file_get_contents($this->overlay);
    touch($this->root.'/ddev-fail');

    $process = ($this->server)('nginx-fpm');

    expect($process->getExitCode())->toBe(1)
        ->and(file_get_contents($this->overlay))->toBe($previous);

    foreach ($this->activated as $file) {
        expect(file_get_contents($this->root.'/.ddev/'.$file))->toBe('stash '.$file."\n");
    }
});

it('AC-4 frankenphp without its stash changes nothing', function (): void {
    (new Process(['rm', '-rf', $this->root.'/.ddev/servers']))->run();

    $process = ($this->server)('frankenphp');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('stash is incomplete')
        ->and($process->getErrorOutput())->toContain('.ddev/servers/frankenphp/config.frankenphp.yaml')
        ->and($process->getErrorOutput())->not->toContain('Unknown server')
        ->and(file_exists($this->overlay))->toBeFalse()
        ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();
});

it('AC-4 an incomplete frankenphp stash names the missing file and changes nothing', function (): void {
    unlink($this->stash.'/web-build/Caddyfile.frankenphp');

    $process = ($this->server)('frankenphp');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('web-build/Caddyfile.frankenphp is missing')
        ->and(file_exists($this->overlay))->toBeFalse()
        ->and(file_exists($this->root.'/.ddev/docker-compose.frankenphp.yaml'))->toBeFalse()
        ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();
});

it('AC-4 a failed copy during activation restores the previous state without restarting', function (): void {
    file_put_contents($this->root.'/.ddev/web-build', 'not a directory');

    $process = ($this->server)('frankenphp');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('aborted')
        ->and(file_exists($this->overlay))->toBeFalse()
        ->and(file_exists($this->root.'/.ddev/docker-compose.frankenphp.yaml'))->toBeFalse()
        ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();
});

it('AC-3 frankenphp active with a missing activated file is re-applied, not skipped', function (): void {
    ($this->server)('frankenphp');
    unlink($this->root.'/.ddev/web-build/Dockerfile.frankenphp');
    unlink($this->root.'/ddev-calls.log');

    $process = ($this->server)('frankenphp');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('re-applying')
        ->and($process->getOutput())->not->toContain('already active')
        ->and(file_get_contents($this->root.'/.ddev/web-build/Dockerfile.frankenphp'))->toBe("stash web-build/Dockerfile.frankenphp\n")
        ->and(file_get_contents($this->root.'/ddev-calls.log'))->toContain('restart');
});

it('AC-3 frankenphp active with an outdated stash is re-applied', function (): void {
    ($this->server)('frankenphp');
    file_put_contents($this->stash.'/config.frankenphp.yaml', "#ddev-generated\nwebserver_type: generic\nwebimage_extra_packages: [php-zts-opcache]\n");

    $process = ($this->server)('frankenphp');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('re-applying')
        ->and(file_get_contents($this->overlay))->toContain('php-zts-opcache');
});

it('MNC-1 frankenphp switches never modify config.yaml', function (): void {
    ($this->server)('frankenphp');
    ($this->server)('nginx-fpm');
    touch($this->root.'/ddev-fail');
    ($this->server)('frankenphp');

    expect(file_get_contents($this->root.'/.ddev/config.yaml'))->toBe($this->configBefore);
});

it('AC-2 AC-3 frankenphp already active is a no-op and switching back to nginx-fpm succeeds', function (): void {
    ($this->server)('frankenphp');
    unlink($this->root.'/ddev-calls.log');
    $overlay = file_get_contents($this->overlay);

    $again = ($this->server)('frankenphp');

    expect($again->getExitCode())->toBe(0)
        ->and($again->getOutput())->toContain('frankenphp already active')
        ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse()
        ->and(file_get_contents($this->overlay))->toBe($overlay);

    foreach ($this->activated as $file) {
        expect(file_get_contents($this->root.'/.ddev/'.$file))->toBe('stash '.$file."\n");
    }

    $back = ($this->server)('nginx-fpm');

    expect($back->getExitCode())->toBe(0)
        ->and($back->getOutput())->toContain('Active: nginx-fpm')
        ->and(file_get_contents($this->root.'/ddev-calls.log'))->toContain('restart')
        ->and(file_get_contents($this->overlay))->toContain('# server: nginx-fpm');

    foreach ($this->activated as $file) {
        expect(file_exists($this->root.'/.ddev/'.$file))->toBeFalse();
    }
});

describe('worker servers', function (): void {
    beforeEach(function (): void {
        $this->octaneStash = $this->root.'/.ddev/servers/frankenphp-octane';
        $this->workerStash = $this->root.'/.ddev/servers/frankenphp-worker';
        mkdir($this->octaneStash, 0777, true);
        mkdir($this->workerStash.'/web-build', 0777, true);
        file_put_contents($this->octaneStash.'/config.frankenphp-octane.yaml', "webserver_type: generic\nweb_extra_daemons: octane\n");
        file_put_contents($this->workerStash.'/web-build/Caddyfile.frankenphp', "worker caddyfile\n");
        $this->caddyfile = $this->root.'/.ddev/web-build/Caddyfile.frankenphp';
    });

    it('AC-1 frankenphp-octane and frankenphp-worker activate their files and overlays', function (): void {
        $octane = ($this->server)('frankenphp-octane');

        expect($octane->getExitCode())->toBe(0)
            ->and($octane->getOutput())->toContain('Active: frankenphp-octane')
            ->and(file_get_contents($this->overlay))->toBe("# server: frankenphp-octane\nwebserver_type: generic\nweb_extra_daemons: octane\n")
            ->and(file_get_contents($this->root.'/ddev-calls.log'))->toContain('restart')
            ->and(trim(($this->server)('--current')->getOutput()))->toBe('frankenphp-octane');

        foreach ($this->activated as $file) {
            expect(file_get_contents($this->root.'/.ddev/'.$file))->toBe('stash '.$file."\n");
        }

        $worker = ($this->server)('frankenphp-worker');

        expect($worker->getExitCode())->toBe(0)
            ->and($worker->getOutput())->toContain('Active: frankenphp-worker')
            ->and(file_get_contents($this->overlay))->toBe("# server: frankenphp-worker\n#ddev-generated\nwebserver_type: generic\n")
            ->and(trim(($this->server)('--current')->getOutput()))->toBe('frankenphp-worker')
            ->and(file_get_contents($this->caddyfile))->toBe("worker caddyfile\n")
            ->and(file_get_contents($this->root.'/.ddev/docker-compose.frankenphp.yaml'))->toBe("stash docker-compose.frankenphp.yaml\n")
            ->and(file_get_contents($this->root.'/.ddev/web-build/Dockerfile.frankenphp'))->toBe("stash web-build/Dockerfile.frankenphp\n")
            ->and(file_get_contents($this->root.'/.ddev/web-build/pre.Dockerfile.frankenphp'))->toBe("stash web-build/pre.Dockerfile.frankenphp\n");
    });

    it('AC-2 switching from frankenphp-worker to frankenphp restores the unedited Caddyfile', function (): void {
        ($this->server)('frankenphp-worker');
        $process = ($this->server)('frankenphp');

        expect($process->getExitCode())->toBe(0)
            ->and(file_get_contents($this->caddyfile))->toBe("stash web-build/Caddyfile.frankenphp\n")
            ->and(file_get_contents($this->overlay))->toContain('# server: frankenphp');

        ($this->server)('frankenphp-worker');
        expect(file_get_contents($this->caddyfile))->toBe("worker caddyfile\n");

        ($this->server)('frankenphp-octane');
        expect(file_get_contents($this->caddyfile))->toBe("stash web-build/Caddyfile.frankenphp\n")
            ->and(file_get_contents($this->overlay))->toContain('# server: frankenphp-octane');

        ($this->server)('frankenphp-worker');
        expect(file_get_contents($this->caddyfile))->toBe("worker caddyfile\n");

        $away = ($this->server)('nginx-fpm');

        expect($away->getExitCode())->toBe(0);
        foreach ($this->activated as $file) {
            expect(file_exists($this->root.'/.ddev/'.$file))->toBeFalse();
        }
    });

    it('AC-3 worker servers are supported and answer on the project URL', function (): void {
        expect(($this->server)()->getOutput())->toContain('Supported: nginx-fpm apache-fpm frankenphp openlitespeed frankenphp-octane frankenphp-worker');

        foreach (['frankenphp-octane', 'frankenphp-worker'] as $server) {
            ($this->server)($server);
            unlink($this->root.'/ddev-calls.log');

            expect(trim(($this->server)('--url')->getOutput()))->toBe('https://ai-lab.ddev.site');

            $again = ($this->server)($server);

            expect($again->getOutput())->toContain($server.' already active')
                ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();
        }
    });

    it('AC-3 an outdated or missing file re-applies the active worker server', function (): void {
        ($this->server)('frankenphp-worker');
        file_put_contents($this->caddyfile, "edited\n");

        $process = ($this->server)('frankenphp-worker');

        expect($process->getOutput())->toContain('re-applying')
            ->and(file_get_contents($this->caddyfile))->toBe("worker caddyfile\n");
    });

    it('AC-3 an incomplete worker stash names the missing file and changes nothing', function (): void {
        unlink($this->workerStash.'/web-build/Caddyfile.frankenphp');
        $worker = ($this->server)('frankenphp-worker');

        unlink($this->octaneStash.'/config.frankenphp-octane.yaml');
        $octane = ($this->server)('frankenphp-octane');

        file_put_contents($this->octaneStash.'/config.frankenphp-octane.yaml', "webserver_type: generic\n");
        unlink($this->stash.'/web-build/Dockerfile.frankenphp');
        $reused = ($this->server)('frankenphp-octane');

        expect($worker->getExitCode())->toBe(1)
            ->and($worker->getErrorOutput())->toContain('.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp is missing')
            ->and($octane->getExitCode())->toBe(1)
            ->and($octane->getErrorOutput())->toContain('.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml is missing')
            ->and($reused->getErrorOutput())->toContain('.ddev/servers/frankenphp/web-build/Dockerfile.frankenphp is missing')
            ->and(file_exists($this->overlay))->toBeFalse()
            ->and(file_exists($this->caddyfile))->toBeFalse()
            ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();
    });

    it('AC-3 failed restart rolls back a switch between the worker servers', function (): void {
        ($this->server)('frankenphp-octane');
        $overlay = file_get_contents($this->overlay);
        touch($this->root.'/ddev-fail');

        $process = ($this->server)('frankenphp-worker');

        expect($process->getExitCode())->toBe(1)
            ->and(file_get_contents($this->overlay))->toBe($overlay)
            ->and(file_get_contents($this->caddyfile))->toBe("stash web-build/Caddyfile.frankenphp\n");

        unlink($this->root.'/ddev-fail');
        ($this->server)('frankenphp-worker');
        touch($this->root.'/ddev-fail');
        ($this->server)('frankenphp-octane');

        expect(file_get_contents($this->caddyfile))->toBe("worker caddyfile\n");
    });

    it('MNC-1 never modifies config.yaml when switching worker servers', function (): void {
        ($this->server)('frankenphp-octane');
        ($this->server)('frankenphp-worker');
        ($this->server)('nginx-fpm');

        expect(file_get_contents($this->root.'/.ddev/config.yaml'))->toBe($this->configBefore);
    });

    it('AC-1 the committed worker stashes carry the overlay, Caddyfile and change list', function (): void {
        $octane = file_get_contents(base_path('.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml'));
        $classic = file_get_contents(base_path('.ddev/servers/frankenphp/config.frankenphp.yaml'));
        $caddyfile = file_get_contents(base_path('.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp'));

        expect($octane)->toContain('webserver_type: generic')
            ->and($octane)->toContain('env OCTANE_HTTPS=true php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --workers=4 --max-requests=500 --log-level=warn')
            ->and($octane)->toContain('directory: /var/www/html')
            ->and($octane)->toContain('container_port: 8000')
            ->and($octane)->toContain('http_port: 80')
            ->and($octane)->toContain('https_port: 443')
            ->and($octane)->not->toContain('frankenphp run')
            ->and(substr($octane, strpos($octane, 'webimage_extra_packages')))->toBe(substr($classic, strpos($classic, 'webimage_extra_packages')))
            ->and($caddyfile)->toContain('frankenphp-worker.php')
            ->and($caddyfile)->toContain('num {$WORKER_NUM:4}')
            ->and($caddyfile)->toContain('MAX_REQUESTS {$WORKER_MAX_REQUESTS:500}')
            ->and($caddyfile)->toContain('REQUEST_MAX_EXECUTION_TIME 30')
            ->and($caddyfile)->toContain('try_files {path} frankenphp-worker.php')
            ->and(substr_count($caddyfile, 'worker {'))->toBe(1)
            ->and($caddyfile)->not->toContain('match *')
            ->and($caddyfile)->toContain('env FRANKENPHP_WORKER 1')
            ->and($caddyfile)->toContain('env APP_BASE_PATH /var/www/html')
            ->and($caddyfile)->toContain('env APP_PUBLIC_PATH /var/www/html/public')
            ->and($caddyfile)->toContain('env LARAVEL_OCTANE 1')
            ->and($caddyfile)->toContain('map {header.X-Forwarded-Proto} {forwarded_https}')
            ->and($caddyfile)->toContain('env HTTPS {forwarded_https}')
            ->and(is_file(base_path('.ddev/servers/frankenphp-worker/CHANGES.md')))->toBeTrue();
    });
});

describe('openlitespeed', function (): void {
    beforeEach(function (): void {
        $this->olsStash = $this->root.'/.ddev/servers/openlitespeed';
        $this->olsCompose = $this->root.'/.ddev/docker-compose.openlitespeed.yaml';
        $this->olsStashFiles = [
            'docker-compose.openlitespeed.yaml',
            'Dockerfile',
            'conf/httpd_config.conf',
            'conf/vhosts/interview/vhconf.conf',
            'conf/zz-opcache.ini',
            'VERSION',
        ];
        mkdir($this->olsStash.'/conf/vhosts/interview', 0777, true);
        foreach ($this->olsStashFiles as $file) {
            file_put_contents($this->olsStash.'/'.$file, 'stash '.$file."\n");
        }
    });

    it('AC-1 openlitespeed activates its compose file and keeps web on nginx-fpm', function (): void {
        $process = ($this->server)('openlitespeed');

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toContain('Active: openlitespeed')
            ->and(file_get_contents($this->overlay))->toBe("# server: openlitespeed\nwebserver_type: nginx-fpm\n")
            ->and(file_get_contents($this->olsCompose))->toBe("stash docker-compose.openlitespeed.yaml\n")
            ->and(file_exists($this->root.'/.ddev/Dockerfile'))->toBeFalse()
            ->and(file_get_contents($this->root.'/ddev-calls.log'))->toContain('restart')
            ->and(trim(($this->server)('--current')->getOutput()))->toBe('openlitespeed');
    });

    it('AC-2 switching away from openlitespeed removes its compose file', function (): void {
        ($this->server)('openlitespeed');
        $process = ($this->server)('nginx-fpm');

        expect($process->getExitCode())->toBe(0)
            ->and(file_exists($this->olsCompose))->toBeFalse()
            ->and(file_get_contents($this->overlay))->toContain('# server: nginx-fpm')
            ->and(file_exists($this->olsStash.'/docker-compose.openlitespeed.yaml'))->toBeTrue();
    });

    it('AC-2 switching from frankenphp to openlitespeed swaps the activated files', function (): void {
        ($this->server)('frankenphp');
        ($this->server)('openlitespeed');

        expect(file_exists($this->olsCompose))->toBeTrue()
            ->and(file_exists($this->root.'/.ddev/docker-compose.frankenphp.yaml'))->toBeFalse();
    });

    it('AC-3 openlitespeed is a supported server', function (): void {
        expect(($this->server)()->getOutput())->toContain('Supported: nginx-fpm apache-fpm frankenphp openlitespeed');
    });

    it('AC-3 openlitespeed already active is a no-op, a missing compose file is re-applied', function (): void {
        ($this->server)('openlitespeed');
        unlink($this->root.'/ddev-calls.log');

        $again = ($this->server)('openlitespeed');

        expect($again->getExitCode())->toBe(0)
            ->and($again->getOutput())->toContain('openlitespeed already active')
            ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();

        unlink($this->olsCompose);
        $reapplied = ($this->server)('openlitespeed');

        expect($reapplied->getOutput())->toContain('re-applying')
            ->and(file_exists($this->olsCompose))->toBeTrue();
    });

    it('AC-3 an incomplete openlitespeed stash names the missing file and changes nothing', function (): void {
        unlink($this->olsStash.'/conf/httpd_config.conf');

        $process = ($this->server)('openlitespeed');

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('conf/httpd_config.conf is missing')
            ->and(file_exists($this->overlay))->toBeFalse()
            ->and(file_exists($this->olsCompose))->toBeFalse()
            ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();
    });

    it('AC-3 an openlitespeed stash without VERSION is incomplete and changes nothing', function (): void {
        unlink($this->olsStash.'/VERSION');

        $process = ($this->server)('openlitespeed');

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('VERSION is missing')
            ->and(file_exists($this->overlay))->toBeFalse()
            ->and(file_exists($this->olsCompose))->toBeFalse()
            ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();
    });

    it('AC-3 failed restart rolls back the openlitespeed switch', function (): void {
        touch($this->root.'/ddev-fail');

        $process = ($this->server)('openlitespeed');

        expect($process->getExitCode())->toBe(1)
            ->and(file_exists($this->overlay))->toBeFalse()
            ->and(file_exists($this->olsCompose))->toBeFalse();
    });

    it('MNC-1 openlitespeed switches never modify config.yaml', function (): void {
        ($this->server)('openlitespeed');
        ($this->server)('nginx-fpm');

        expect(file_get_contents($this->root.'/.ddev/config.yaml'))->toBe($this->configBefore);
    });

    it('AC-1 openlitespeed compose path is gitignored', function (): void {
        expect(file(base_path('.gitignore'), FILE_IGNORE_NEW_LINES))->toContain('.ddev/docker-compose.openlitespeed.yaml');
    });
});

it('AC-7 --url prints the URL the active server answers on', function (): void {
    expect(trim(($this->server)('--url')->getOutput()))->toBe('https://ai-lab.ddev.site');

    file_put_contents($this->overlay, "# server: openlitespeed\nwebserver_type: nginx-fpm\n");

    expect(trim(($this->server)('--url')->getOutput()))->toBe('https://ai-lab.ddev.site:8091')
        ->and(file_exists($this->root.'/ddev-calls.log'))->toBeFalse();
});

it('switching servers removes bench overlays so they never carry over to another server', function (): void {
    mkdir($this->root.'/.ddev/web-entrypoint.d', 0777, true);
    mkdir($this->root.'/.ddev/nginx_full', 0777, true);
    file_put_contents($this->root.'/.ddev/config.zzz-bench-concurrency.yaml', "override_config: true\n");
    file_put_contents($this->root.'/.ddev/web-entrypoint.d/zz-bench-fpm.sh', "#!/bin/sh\n");
    file_put_contents($this->root.'/.ddev/nginx_full/nginx-site.conf', "edited\n");
    file_put_contents($this->root.'/.ddev/nginx_full/nginx-site.conf.bench-orig', "#ddev-generated\noriginal\n");

    $process = ($this->server)('apache-fpm');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_exists($this->root.'/.ddev/config.zzz-bench-concurrency.yaml'))->toBeFalse()
        ->and(file_exists($this->root.'/.ddev/web-entrypoint.d/zz-bench-fpm.sh'))->toBeFalse()
        ->and(file_get_contents($this->root.'/.ddev/nginx_full/nginx-site.conf'))->toBe("#ddev-generated\noriginal\n");
});

it('re-running the active server with a leftover bench overlay resets it instead of saying already active', function (): void {
    file_put_contents($this->root.'/.ddev/config.zzz-bench-concurrency.yaml', "override_config: true\n");

    $process = ($this->server)('nginx-fpm');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->not->toContain('already active')
        ->and(file_exists($this->root.'/.ddev/config.zzz-bench-concurrency.yaml'))->toBeFalse();
});
