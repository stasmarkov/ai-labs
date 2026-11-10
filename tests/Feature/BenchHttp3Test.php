<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-http3-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app');
    file_put_contents($this->root.'/app/.env', "APP_NAME=x\nEARLY_HINTS=true\n");
    file_put_contents($this->root.'/state', "frankenphp-octane\n");

    $stubs = [
        'ddev' => <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$*" in
  "server --current") cat "$STUB_STATE" ;;
  "server --url") echo "https://ai-lab.ddev.site" ;;
  "server --default") echo "${STUB_DEFAULT-frankenphp}" ;;
  "server "*) echo "${2}" > "$STUB_STATE" ;;
esac
BASH,
        'git' => <<<'BASH'
#!/usr/bin/env bash
echo abc1234
BASH,
        'docker' => <<<'BASH'
#!/usr/bin/env bash
echo "docker $*" >> "$STUB_LOG"
case "$1" in
  pull) ;;
  image) echo "stub/curl@sha256:abc" ;;
  inspect) echo 172.19.0.2 ;;
  run)
    if [[ "$*" == *--http2* ]]; then
      cp "$BENCH_APPROOT/.env" "$STUB_ENV_SEEN"
    fi
    if [[ "$*" == *--http3-only* ]]; then
      if [[ -n "${STUB_H3_EXIT-}" ]]; then
        exit "$STUB_H3_EXIT"
      fi
      echo "* Connected to ai-lab.ddev.site (172.19.0.2) port 443" >&2
      echo "*  subject: O=mkcert development certificate; OU=someuser@somehost" >&2
      echo "*  issuer: O=mkcert development CA; OU=someuser@somehost" >&2
      echo "* ALPN: server accepted h3" >&2
      echo "< HTTP/3 200 " >&2
      echo "< content-type: text/html" >&2
      printf '%s' "${STUB_H3_BODY-<html>listings</html>}"
    else
      echo "* Connected to ai-lab.ddev.site (172.19.0.2) port 443" >&2
      echo "*  subject: O=mkcert development certificate; OU=someuser@somehost" >&2
      echo "* ALPN: server accepted h2" >&2
      echo "< HTTP/2 ${STUB_H2_STATUS-200} " >&2
      echo "< set-cookie: laravel_session=secret; path=/" >&2
      if [[ "$(cat "$STUB_STATE")" == frankenphp ]]; then
        echo '< alt-svc: h3=":443"; ma=2592000' >&2
      fi
      printf '%s' '<html>listings</html>'
    fi
    ;;
esac
BASH,
    ];
    foreach ($stubs as $name => $script) {
        file_put_contents($this->root.'/bin/'.$name, $script);
        chmod($this->root.'/bin/'.$name, 0755);
    }

    $this->http3 = function (array $env, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('bench/http3.sh'), ...$args],
            $this->root,
            $env + [
                'BENCH_APPROOT' => $this->root.'/app',
                'BENCH_DATE' => '2026-11-05',
                'STUB_LOG' => $this->root.'/calls.log',
                'STUB_STATE' => $this->root.'/state',
                'STUB_ENV_SEEN' => $this->root.'/env-during',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->run();

        return $process;
    };
    $this->dir = fn (): string => $this->root.'/app/bench/results/http3/2026-11-05';
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-1 http3 records one h3 request with status 200, the same body hash as h2, the Alt-Svc header and alpn_h2', function (): void {
    $process = ($this->http3)([]);
    $calls = file_get_contents($this->root.'/calls.log');
    $result = json_decode(file_get_contents(($this->dir)().'/frankenphp.json'), true);
    $hash = hash('sha256', '<html>listings</html>');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('--http3-only', '--http2', '--network ddev_default', '--resolve ai-lab.ddev.site:443:172.19.0.2', 'https://ai-lab.ddev.site/listings/1')
        ->and($result)->toMatchArray([
            'server' => 'frankenphp', 'commit' => 'abc1234', 'path' => '/listings/1', 'h3_ok' => true, 'h3_status' => 200, 'h3_alpn' => 'h3',
            'h2_status' => 200, 'alpn_h2' => 'h2', 'alt_svc' => 'h3=":443"; ma=2592000',
            'body_sha256_h2' => $hash, 'body_sha256_h3' => $hash, 'reason' => null,
            'curl_image_digest' => 'stub/curl@sha256:abc',
        ])
        ->and($result)->not->toHaveKey('h2_alpn')
        ->and($process->getOutput())->toContain('h3_ok=yes');
});

it('AC-1 http3 sets EARLY_HINTS=false for the requests, records why, and restores .env', function (): void {
    $process = ($this->http3)([]);
    $result = json_decode(file_get_contents(($this->dir)().'/frankenphp.json'), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->root.'/env-during'))->toContain('EARLY_HINTS=false')->not->toContain('EARLY_HINTS=true')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe("APP_NAME=x\nEARLY_HINTS=true\n")
        ->and($this->root.'/app/.env.bench-backup')->not->toBeFile()
        ->and($result['early_hints_during_h3'])->toBeFalse()
        ->and($result['early_hints_reason'])->toContain('103')
        ->and(file_get_contents($this->root.'/calls.log'))->toContain('ddev artisan config:clear');
});

it('AC-3 http3 switches to frankenphp itself and the trap puts the default server back', function (): void {
    $process = ($this->http3)(['STUB_DEFAULT' => 'frankenphp-worker']);
    $servers = array_values(array_filter(explode("\n", file_get_contents($this->root.'/calls.log')), fn (string $line): bool => preg_match('/^ddev server (frankenphp|frankenphp-worker)$/', $line) === 1));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($servers)->toBe(['ddev server frankenphp', 'ddev server frankenphp-worker'])
        ->and(trim(file_get_contents($this->root.'/state')))->toBe('frankenphp-worker');
});

it('AC-3 http3 puts the default server back and writes no result when the h2 request fails', function (): void {
    $process = ($this->http3)(['STUB_H2_STATUS' => '500', 'STUB_DEFAULT' => 'frankenphp-worker']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('not 200')
        ->and(($this->dir)().'/frankenphp.json')->not->toBeFile()
        ->and(trim(file_get_contents($this->root.'/state')))->toBe('frankenphp-worker')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe("APP_NAME=x\nEARLY_HINTS=true\n");
});

it('AC-1 http3 with --server current switches nothing', function (): void {
    file_put_contents($this->root.'/state', "frankenphp\n");
    $process = ($this->http3)([], '--server', 'current');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->root.'/calls.log'))->not->toMatch('/^ddev server (frankenphp|frankenphp-octane)$/m');
});

it('AC-1 http3 reports h3_ok false with the curl exit code on frankenphp-octane and exits 0', function (): void {
    $process = ($this->http3)(['STUB_H3_EXIT' => '28'], '--server', 'frankenphp-octane');
    $result = json_decode(file_get_contents(($this->dir)().'/frankenphp-octane.json'), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result['h3_ok'])->toBeFalse()
        ->and($result['h3_status'])->toBeNull()
        ->and($result['h3_alpn'])->toBeNull()
        ->and($result['alt_svc'])->toBeNull()
        ->and($result['reason'])->toBe('no HTTP/3: curl exit 28')
        ->and($process->getOutput())->toContain('h3_ok=no');
});

it('AC-1 NFR-2 http3 fails and keeps both bodies outside the committed results when the h3 body differs from the h2 body', function (): void {
    $process = ($this->http3)(['STUB_H3_BODY' => '<html>other</html>']);
    $result = json_decode(file_get_contents(($this->dir)().'/frankenphp.json'), true);
    $raw = $this->root.'/app/bench/results/raw';

    expect($process->getExitCode())->toBe(1)
        ->and($result['h3_ok'])->toBeFalse()
        ->and($result['reason'])->toBe('body sha256 differs')
        ->and($raw.'/http3-frankenphp-body-h2.html')->toBeFile()
        ->and($raw.'/http3-frankenphp-body-h3.html')->toBeFile()
        ->and(glob(($this->dir)().'/*.html'))->toBe([]);
});

it('AC-1 http3 stops without a result when the h2 request fails', function (): void {
    file_put_contents($this->root.'/bin/docker', "#!/usr/bin/env bash\ncase \"\$1\" in image) echo stub/curl@sha256:abc ;; inspect) echo 172.19.0.2 ;; run) exit 7 ;; esac\n");

    $process = ($this->http3)([]);

    expect($process->getExitCode())->not->toBe(0)
        ->and(($this->dir)().'/frankenphp.json')->not->toBeFile();
});

it('NFR-2 http3 redacts certificate lines, IP addresses and cookies from the saved trace', function (): void {
    $process = ($this->http3)([]);
    $trace = file_get_contents(($this->dir)().'/frankenphp.trace');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($trace)->toContain('Connected to ai-lab.ddev.site (<ip>) port 443', '< HTTP/3 200', '< HTTP/2 200', 'alt-svc')
        ->not->toContain('subject:', 'issuer:', 'mkcert', 'OU=', '172.19.0.2', 'laravel_session');
});

it('AC-3 http3-matrix measures on frankenphp, runs the four variants and restores the default server last', function (): void {
    $process = new Process(['bash', base_path('bench/http3-matrix.sh'), '--dry-run', '--runs', '1'], $this->root, [
        'BENCH_APPROOT' => $this->root.'/app',
        'STUB_LOG' => $this->root.'/calls.log',
        'STUB_STATE' => $this->root.'/state',
        'STUB_DEFAULT' => 'frankenphp-worker',
        'PATH' => $this->root.'/bin:'.getenv('PATH'),
    ]);
    $process->run();
    $lines = array_values(array_filter(explode("\n", $process->getOutput())));
    $variants = array_values(array_filter($lines, fn (string $line): bool => str_contains($line, 'lcp.mjs')));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($lines[0])->toBe('ddev server frankenphp')
        ->and($variants)->toHaveCount(4)
        ->and($variants[0])->toContain('--variant h2-loss0')
        ->and($variants[3])->toContain('--variant h3-loss2')
        ->and(end($lines))->toBe('ddev server frankenphp-worker')
        ->and(array_keys(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'ddev server '))))->toBe([0, count($lines) - 1]);
});

it('AC-3 http3-matrix exits 2 without any server switch when the default cannot be read', function (): void {
    $process = new Process(['bash', base_path('bench/http3-matrix.sh'), '--dry-run', '--runs', '1'], $this->root, [
        'BENCH_APPROOT' => $this->root.'/app',
        'STUB_LOG' => $this->root.'/calls.log',
        'STUB_STATE' => $this->root.'/state',
        'STUB_DEFAULT' => '',
        'PATH' => $this->root.'/bin:'.getenv('PATH'),
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->not->toContain('ddev server frankenphp')
        ->and(substr_count($process->getErrorOutput(), 'Cannot read the default server'))->toBe(1);
});

it('AC-2 http3 exits non-zero for a removed server and switches nothing', function (string $server): void {
    $process = ($this->http3)([], '--server', $server);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain($server.' was removed')
        ->and(file_exists($this->root.'/calls.log') ? file_get_contents($this->root.'/calls.log') : '')->not->toContain('ddev server');
})->with(['nginx-fpm', 'apache-fpm', 'openlitespeed']);
