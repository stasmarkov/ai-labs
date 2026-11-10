<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-early-hints-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app');

    $trace = <<<'BASH'
echo "* Connected to ai-lab.ddev.site (172.19.0.2) port 443" >&2
echo "*  subject: O=mkcert development certificate; OU=someuser@somehost" >&2
echo "*  issuer: O=mkcert development CA; OU=someuser@somehost" >&2
echo "* using HTTP/2" >&2
echo "> GET /listings/1 HTTP/2" >&2
if [[ -n "${STUB_103-}" ]]; then
  echo "< HTTP/2 103 " >&2
  echo "< link: </build/assets/app.css>; rel=\"preload\"; as=\"style\"" >&2
fi
echo "< HTTP/2 200 " >&2
echo "< content-type: text/html" >&2
BASH;

    $stubs = [
        'ddev' => <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$*" in
  "server --current") echo "${STUB_SERVER-frankenphp}" ;;
  "server --url") echo "https://ai-lab.ddev.site" ;;
esac
BASH,
        'curl' => "#!/usr/bin/env bash\necho \"curl \$*\" >> \"\$STUB_LOG\"\n{$trace}\n",
        'git' => <<<'BASH'
#!/usr/bin/env bash
echo abc1234
BASH,
        'docker' => <<<BASH
#!/usr/bin/env bash
echo "docker \$*" >> "\$STUB_LOG"
case "\$1" in
  pull) ;;
  image) echo "stub/curl@sha256:abc" ;;
  inspect) echo 172.19.0.2 ;;
  run)
{$trace}
    ;;
esac
BASH,
    ];
    foreach ($stubs as $name => $script) {
        file_put_contents($this->root.'/bin/'.$name, $script);
        chmod($this->root.'/bin/'.$name, 0755);
    }

    $this->earlyHints = function (array $env, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('bench/early-hints.sh'), ...$args],
            $this->root,
            $env + [
                'BENCH_APPROOT' => $this->root.'/app',
                'BENCH_DATE' => '2026-11-05',
                'STUB_LOG' => $this->root.'/calls.log',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->run();

        return $process;
    };
    $this->dir = fn (): string => $this->root.'/app/bench/results/early-hints/2026-11-05';
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-3 early-hints records a 103 through the router and straight at the web container and saves both traces', function (): void {
    $process = ($this->earlyHints)(['STUB_103' => '1']);
    $calls = file_get_contents($this->root.'/calls.log');
    $result = json_decode(file_get_contents(($this->dir)().'/frankenphp.json'), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('--http2', 'https://ai-lab.ddev.site/listings/1', '--network ddev_default', '--resolve ai-lab.ddev.site:443:172.19.0.2')
        ->and($result)->toMatchArray([
            'server' => 'frankenphp', 'commit' => 'abc1234', 'listing' => 1, 'router_103' => true, 'direct_103' => true,
            'probe_image_digest' => 'stub/curl@sha256:abc',
        ])
        ->and(($this->dir)().'/frankenphp-router.trace')->toBeFile()
        ->and(file_get_contents(($this->dir)().'/frankenphp-direct.trace'))->toContain('< HTTP/2 103')
        ->and($calls)->not->toContain('--add-host')
        ->and($process->getOutput())->toContain('router_103=yes', 'direct_103=yes');
});

it('AC-3 early-hints records no 103 on nginx-fpm', function (): void {
    $process = ($this->earlyHints)(['STUB_SERVER' => 'nginx-fpm']);
    $result = json_decode(file_get_contents(($this->dir)().'/nginx-fpm.json'), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result['router_103'])->toBeFalse()
        ->and($result['direct_103'])->toBeFalse()
        ->and($process->getOutput())->toContain('router_103=no', 'direct_103=no');
});

it('AC-3 early-hints stops when a request fails instead of recording a false negative', function (): void {
    file_put_contents($this->root.'/bin/curl', "#!/usr/bin/env bash\nexit 7\n");

    $process = ($this->earlyHints)([]);

    expect($process->getExitCode())->not->toBe(0)
        ->and(($this->dir)().'/frankenphp.json')->not->toBeFile();
});

it('AC-3 early-hints redacts certificate lines and IP addresses from the saved traces', function (): void {
    $process = ($this->earlyHints)(['STUB_103' => '1']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    foreach (['router', 'direct'] as $path) {
        $trace = file_get_contents(($this->dir)().'/frankenphp-'.$path.'.trace');

        expect($trace)->toContain('Connected to ai-lab.ddev.site (<ip>) port 443', '< HTTP/2 103')
            ->not->toContain('subject:', 'issuer:', 'mkcert', 'OU=', '172.19.0.2');
    }
});
