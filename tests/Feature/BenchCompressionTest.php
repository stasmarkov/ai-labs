<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->body = 'export-body';
    $this->sha = hash('sha256', $this->body);
    $this->root = sys_get_temp_dir().'/bench-compression-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app');
    file_put_contents($this->root.'/app/.env', "APP_NAME=x\nDEBUG_ROUTES=false\n");

    $stubs = [
        'ddev' => <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$*" in
  "server --current") echo "${STUB_SERVER-frankenphp}" ;;
  "server --url") echo "https://interview.ddev.site" ;;
  "exec stat -c %s storage/app/exports/listings.csv") echo 11 ;;
  "artisan tinker --execute"*) echo "11 ${STUB_RECORD_SHA-$STUB_SHA}" ;;
  "exec cat /sys/fs/cgroup/cpu.stat")
    n=$(cat "$STUB_CPU" 2>/dev/null || echo 1000000)
    n=$((n + 500000))
    echo "$n" > "$STUB_CPU"
    echo "usage_usec $n"
    echo "user_usec 1"
    ;;
esac
BASH,
        'docker' => <<<'BASH'
#!/usr/bin/env bash
echo "docker $*" >> "$STUB_LOG"
case "$1" in
  inspect) echo 172.19.0.2 ;;
  image) echo "curlimages/curl@sha256:abc" ;;
  run)
    shift
    while [[ $# -gt 0 && "$1" != curlimages/* ]]; do shift; done
    shift
    exec curl "$@" ;;
esac
BASH,
        'git' => "#!/usr/bin/env bash\necho abc1234\n",
        'curl' => <<<'BASH'
#!/usr/bin/env bash
echo "curl $*" >> "$STUB_LOG"
[[ -n "${STUB_CURL_FAIL-}" ]] && exit 7
hdr=""; out=""; enc="identity"; compressed=0; url=""; write=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    -D) hdr="$2"; shift 2 ;;
    -o) out="$2"; shift 2 ;;
    -H) case "$2" in Accept-Encoding:*) enc="${2#Accept-Encoding: }" ;; esac; shift 2 ;;
    --compressed) compressed=1; shift ;;
    --max-time) shift 2 ;;
    -w) write="$2"; shift 2 ;;
    -*) shift ;;
    *) url="$1"; shift ;;
  esac
done
chosen=""
case "$url" in
  *"/exports/"*|*"/build/"*|*"/listings/"*)
    if [[ "${STUB_SERVER-frankenphp}" == nginx-fpm ]]; then
      [[ "$enc" == *gzip* ]] && chosen="gzip"
    else
      case "$enc" in
        identity) chosen="" ;;
        "zstd, br, gzip") chosen="zstd" ;;
        *) chosen="$enc" ;;
      esac
    fi ;;
esac
[[ -n "${STUB_IDENTITY_ENC-}" && "$url" == *"/exports/"* && "$enc" == identity ]] && chosen="gzip"
if [[ -n "$hdr" ]]; then
  { echo "HTTP/2 200"; [[ -n "$chosen" ]] && echo "content-encoding: $chosen"
    [[ -n "${STUB_DOUBLE-}" && "$url" == *"/exports/"* && -n "$chosen" ]] && echo "content-encoding: gzip"; echo; true; } > "${hdr/#-//dev/stdout}"
fi
if [[ "$url" == *"/exports/"* && -n "$compressed" && "$compressed" == 1 ]]; then
  if [[ -n "${STUB_BAD_SHA-}" ]]; then printf 'tampered'; else printf '%s' "export-body"; fi
elif [[ "$out" == "-" ]]; then
  echo '<link href="/build/assets/app-X.css"><script src="/build/assets/app-Y.js"></script>'
fi
if [[ -n "$write" ]]; then
  if [[ -n "$chosen" ]]; then printf '\n200 400 0.050\n'; else printf '\n200 1000 0.080\n'; fi
fi
BASH,
    ];
    foreach ($stubs as $name => $script) {
        file_put_contents($this->root.'/bin/'.$name, $script);
        chmod($this->root.'/bin/'.$name, 0755);
    }

    $this->compression = function (array $env, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('bench/compression.sh'), ...$args],
            $this->root,
            $env + [
                'BENCH_APPROOT' => $this->root.'/app',
                'BENCH_DATE' => '2026-11-05',
                'BENCH_REPEATS' => '2',
                'STUB_LOG' => $this->root.'/calls.log',
                'STUB_CPU' => $this->root.'/cpu',
                'STUB_SHA' => $this->sha,
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->run();

        return $process;
    };
    $this->file = fn (string $label = 'frankenphp'): string => $this->root.'/app/bench/results/compression/2026-11-05/'.$label.'.json';
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-5 compression records wire bytes and time to the last byte for every resource and encoding', function (): void {
    $process = ($this->compression)([]);
    $result = json_decode(file_get_contents(($this->file)()), true);
    $rows = collect($result['resources']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result)->toMatchArray(['server' => 'frankenphp', 'commit' => 'abc1234', 'date' => '2026-11-05', 'repeats' => 2])
        ->and($rows->pluck('resource')->unique()->values()->all())->toBe(['html', 'css', 'page-js', 'lab-bundle', 'export'])
        ->and($rows->pluck('accept_encoding')->unique()->values()->all())->toBe(['identity', 'gzip', 'br', 'zstd', 'zstd, br, gzip'])
        ->and($rows)->toHaveCount(25)
        ->and($rows->firstWhere(fn ($r) => $r['resource'] === 'css' && $r['accept_encoding'] === 'zstd, br, gzip'))
        ->toMatchArray(['content_encoding' => 'zstd', 'wire_bytes' => 400, 'seconds_median' => 0.05, 'status' => 200])
        ->and($rows->firstWhere(fn ($r) => $r['resource'] === 'html' && $r['accept_encoding'] === 'identity'))
        ->toMatchArray(['content_encoding' => '', 'wire_bytes' => 1000]);
});

it('AC-5 compression resolves the css and js urls from the page instead of hard-coding the fingerprint', function (): void {
    ($this->compression)([]);
    $result = json_decode(file_get_contents(($this->file)()), true);

    expect(collect($result['resources'])->firstWhere('resource', 'css')['path'])->toBe('/build/assets/app-X.css')
        ->and(collect($result['resources'])->firstWhere('resource', 'page-js')['path'])->toBe('/build/assets/app-Y.js');
});

it('AC-5 compression reports the export CPU seconds per encoding from the web container cgroup', function (): void {
    ($this->compression)([]);
    $result = json_decode(file_get_contents(($this->file)()), true);

    expect($result['export']['rows'])->toHaveCount(5)
        ->and($result['export']['rows'][0])->toMatchArray(['accept_encoding' => 'identity', 'cpu_seconds' => 0.5])
        ->and(file_get_contents($this->root.'/calls.log'))->toContain('ddev exec cat /sys/fs/cgroup/cpu.stat');
});

it('AC-6 compression passes when the export has one content-encoding and the decoded sha256 matches the export file', function (): void {
    $process = ($this->compression)([]);
    $result = json_decode(file_get_contents(($this->file)()), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($result['export'])->toMatchArray(['expected_sha256' => $this->sha, 'expected_bytes' => 11, 'ok' => true])
        ->and(collect($result['export']['rows'])->every(fn ($r) => $r['single_encoding'] && $r['sha_ok']))->toBeTrue();
});

it('AC-6 compression fails when the export is compressed twice', function (): void {
    $process = ($this->compression)(['STUB_DOUBLE' => '1']);
    $result = json_decode(file_get_contents(($this->file)()), true);

    expect($process->getExitCode())->toBe(1)
        ->and($result['export']['ok'])->toBeFalse()
        ->and($result['export']['rows'][1]['single_encoding'])->toBeFalse()
        ->and($process->getErrorOutput())->toContain('compressed more than once');
});

it('AC-6 compression fails when the decoded export does not match the recorded sha256', function (): void {
    $process = ($this->compression)(['STUB_BAD_SHA' => '1']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('sha256');
});

it('MNC-3 compression reads the export size and sha256 from the file without changing it', function (): void {
    ($this->compression)([]);
    $calls = file_get_contents($this->root.'/calls.log');

    expect($calls)->toContain('ddev exec stat -c %s storage/app/exports/listings.csv')
        ->and($calls)->not->toContain('exports:generate')
        ->and($calls)->toContain('docker run --rm --network ddev_default --add-host interview.ddev.site:172.19.0.2', '--compressed');
});

it('AC-5 compression records nginx-fpm answering gzip or nothing', function (): void {
    $process = ($this->compression)(['STUB_SERVER' => 'nginx-fpm']);
    $result = json_decode(file_get_contents(($this->file)('nginx-fpm')), true);
    $encodings = collect($result['resources'])->pluck('content_encoding')->unique()->sort()->values()->all();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($encodings)->toBe(['', 'gzip']);
});

it('AC-5 compression writes no file when a request fails', function (): void {
    $process = ($this->compression)(['STUB_CURL_FAIL' => '1']);

    expect($process->getExitCode())->not->toBe(0)
        ->and(($this->file)())->not->toBeFile();
});

it('AC-5 compression refuses an unknown argument and prints usage with --help', function (): void {
    $bad = ($this->compression)([], '--nope');
    $help = ($this->compression)([], '--help');

    expect($bad->getExitCode())->toBe(2)
        ->and($help->getExitCode())->toBe(0)
        ->and($help->getOutput())->toContain('Usage: bench/compression.sh');
});

it('AC-5 compression labels the largest built bundle lab-bundle and keeps the empty page script as page-js', function (): void {
    mkdir($this->root.'/app/public/build/assets', 0777, true);
    file_put_contents($this->root.'/app/public/build/assets/app-Y.js', '');
    file_put_contents($this->root.'/app/public/build/assets/big-Z.js', str_repeat('x', 100));

    ($this->compression)([]);
    $result = json_decode(file_get_contents(($this->file)()), true);

    $rows = collect($result['resources']);

    expect($rows->firstWhere('resource', 'lab-bundle')['path'])->toBe('/build/assets/big-Z.js')
        ->and($rows->firstWhere('resource', 'page-js')['path'])->toBe('/build/assets/app-Y.js')
        ->and($rows->where('resource', 'lab-bundle'))->toHaveCount(5)
        ->and($rows->where('resource', 'page-js'))->toHaveCount(5)
        ->and($rows->contains(fn ($r) => $r['resource'] === 'js'))->toBeFalse();
});

it('AC-5 compression records one CPU sample per repeat and their median', function (): void {
    $five = ($this->compression)(['BENCH_REPEATS' => '5']);
    $result = json_decode(file_get_contents(($this->file)()), true);
    $row = $result['export']['rows'][1];

    expect($five->getExitCode())->toBe(0, $five->getErrorOutput())
        ->and($result['repeats'])->toBe(5)
        ->and($row['cpu_samples'])->toBe([0.5, 0.5, 0.5, 0.5, 0.5])
        ->and($row['cpu_seconds'])->toBe(0.5);
});

it('AC-5 compression default repeats is 5 and the median ignores an outlier', function (): void {
    expect(file_get_contents(base_path('bench/compression.sh')))->toContain('REPEATS="${BENCH_REPEATS:-5}"');

    $median = new Process(['bash', '-c', 'source <(sed -n "/^median() {/,/^}/p" '.escapeshellarg(base_path('bench/compression.sh')).'); printf "%s\n" 0.9 0.1 0.3 0.2 0.2 | median']);
    $median->run();

    $even = new Process(['bash', '-c', 'source <(sed -n "/^median() {/,/^}/p" '.escapeshellarg(base_path('bench/compression.sh')).'); printf "%s\n" 0.4 0.1 0.3 0.2 | median']);
    $even->run();

    expect(trim($median->getOutput()))->toBe('0.2')
        ->and(trim($even->getOutput()))->toBe('0.25');
});

it('AC-6 compression fails when an identity export answers with a content-encoding', function (): void {
    $process = ($this->compression)(['STUB_IDENTITY_ENC' => '1']);
    $result = json_decode(file_get_contents(($this->file)()), true);

    expect($process->getExitCode())->toBe(1)
        ->and($result['export']['ok'])->toBeFalse()
        ->and($result['export']['rows'][0])->toMatchArray(['accept_encoding' => 'identity', 'single_encoding' => false])
        ->and($process->getErrorOutput())->toContain("Accept-Encoding 'identity' answered with Content-Encoding");
});

it('AC-6 compression reads the reference sha256 from the exports record', function (): void {
    $process = ($this->compression)(['STUB_RECORD_SHA' => str_repeat('a', 64)]);
    $result = json_decode(file_get_contents(($this->file)()), true);

    expect($process->getExitCode())->toBe(1)
        ->and($result['export']['expected_sha256'])->toBe(str_repeat('a', 64))
        ->and($result['sha256_source'])->toBe('exports record')
        ->and(file_get_contents($this->root.'/calls.log'))->toContain('ddev artisan tinker --execute')
        ->and(file_get_contents($this->root.'/calls.log'))->not->toContain('sha256sum');
});

it('AC-5 compression runs every request in the pinned curl container over HTTP/2', function (): void {
    ($this->compression)([]);
    $calls = file($this->root.'/calls.log');
    $hostCurl = array_filter($calls, fn (string $line): bool => str_starts_with($line, 'curl ') && ! str_contains(implode('', $calls), 'docker run'));

    expect(file_get_contents($this->root.'/calls.log'))->toContain('docker run --rm --network ddev_default', 'curlimages/curl@sha256:abc -ksS --http2')
        ->and($hostCurl)->toBeEmpty()
        ->and(json_decode(file_get_contents(($this->file)()), true)['client'])
        ->toMatchArray(['image' => 'curlimages/curl:8.11.1', 'image_id' => 'curlimages/curl@sha256:abc', 'protocol' => 'h2']);
});

it('AC-5 compression refuses to run without a resolved image digest', function (): void {
    file_put_contents($this->root.'/bin/docker', "#!/usr/bin/env bash\necho \"docker \$*\" >> \"\$STUB_LOG\"\ncase \"\$1\" in\n  inspect) echo 172.19.0.2 ;;\n  image) exit 1 ;;\nesac\n");

    $process = ($this->compression)([]);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Cannot read the digest')
        ->and(($this->file)())->not->toBeFile()
        ->and(file_get_contents($this->root.'/app/.env'))->toBe("APP_NAME=x\nDEBUG_ROUTES=false\n");
});

it('AC-5 compression downloads the export once per repeat and reuses the rows for the resource table', function (): void {
    ($this->compression)([]);
    $calls = file($this->root.'/calls.log');
    $plain = array_filter($calls, fn (string $l): bool => str_starts_with($l, 'curl ') && str_contains($l, '/exports/listings') && ! str_contains($l, '--compressed'));
    $result = json_decode(file_get_contents(($this->file)()), true);
    $exportRows = collect($result['resources'])->where('resource', 'export');

    expect($plain)->toHaveCount(10)
        ->and($exportRows)->toHaveCount(5)
        ->and($exportRows->first()['seconds'])->toHaveCount(2)
        ->and($exportRows->first()['path'])->toBe('/exports/listings');
});

it('AC-5 compression turns DEBUG_ROUTES on for the run and restores .env and the config cache after', function (): void {
    file_put_contents($this->root.'/bin/ddev', file_get_contents($this->root.'/bin/ddev').'
if [[ "$*" == "artisan config:clear" ]]; then cp "$BENCH_APPROOT/.env" "$STUB_ROOT/env-at-clear-$RANDOM$RANDOM"; fi
');
    $process = ($this->compression)(['STUB_ROOT' => $this->root]);
    $snapshots = glob($this->root.'/env-at-clear-*');
    $contents = array_map('file_get_contents', $snapshots);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->root.'/app/.env'))->toBe("APP_NAME=x\nDEBUG_ROUTES=false\n")
        ->and($this->root.'/app/.env.bench-backup')->not->toBeFile()
        ->and(collect($contents)->contains(fn ($c) => str_contains($c, 'DEBUG_ROUTES=true')))->toBeTrue()
        ->and(collect($contents)->contains(fn ($c) => str_contains($c, 'DEBUG_ROUTES=false')))->toBeTrue();
});
