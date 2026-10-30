<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/parity');
    mkdir($this->root.'/ddev/.ddev', 0777, true);
    $this->writeCurl = function (string $suffix = '', string $runtimeStatus = '200'): void {
        $script = <<<BASH
#!/usr/bin/env bash
out=""
url=""
echo "\$*" >> "$this->root/curl.log"
[[ -n "\${STUB_CURL_FAIL-}" ]] && exit 7
while [[ \$# -gt 0 ]]; do
  case "\$1" in
    -o) out="\$2"; shift 2 ;;
    -w|-H|--max-time) shift 2 ;;
    -*) shift ;;
    *) url="\$1"; shift ;;
  esac
done
if [[ "\$url" == */debug/runtime ]]; then
  if [[ "$runtimeStatus" == "200" ]]; then
    printf '{"data":{"sapi":"%s","php_version":"8.4.1","opcache_enabled":true,"server_software":"nginx/1.28"}}' "\${STUB_SAPI-fpm-fcgi}" > "\$out"
  else
    printf '{"message":"nope"}' > "\$out"
  fi
  printf '%s' "$runtimeStatus"
  exit 0
fi
printf 'body:%s$suffix' "\$url" > "\$out"
header="\${STUB_SERVER_HEADER-auto}"
if [[ "\$header" == "auto" ]]; then
  case "\$(sed -n 's/^# server: *//p' "\$DDEV_APPROOT/.ddev/config.zz-server.yaml" 2>/dev/null)" in
    apache-fpm) header="Apache/2.4.68" ;;
    frankenphp) header="Caddy" ;;
    openlitespeed) header="LiteSpeed" ;;
    *) header="nginx" ;;
  esac
fi
printf '200\t%s\t%s' "\${STUB_CONTENT_TYPE-application/json}" "\$header"
BASH;
        file_put_contents($this->root.'/bin/curl', $script);
        chmod($this->root.'/bin/curl', 0755);
    };
    $this->writeDdev = function (string $server): void {
        $script = <<<BASH
#!/usr/bin/env bash
case "\$1" in
  server) echo "$server" ;;
  version) printf ' ITEM              VALUE \\n DDEV version      v1.25.4 \\n architecture      arm64 \\n' ;;
  exec)
    case "\$2" in
      -s) echo "LiteSpeed V1.9.4 Open" ;;
      nginx) echo "nginx version: nginx/1.26.3" >&2 ;;
      frankenphp) echo "FrankenPHP v1.12.1 PHP 8.4.13 Caddy v2.10.2 h1:abc123=" ;;
      *) echo "Server version: Apache/2.4.68 (Debian)" ;;
    esac ;;
esac
BASH;
        file_put_contents($this->root.'/bin/ddev', $script);
        chmod($this->root.'/bin/ddev', 0755);
    };
    $this->setServer = function (string $server): void {
        file_put_contents($this->root.'/ddev/.ddev/config.yaml', "webserver_type: nginx-fpm\n");
        $webserverType = match ($server) {
            'frankenphp' => 'generic',
            'openlitespeed' => 'nginx-fpm',
            default => $server,
        };
        file_put_contents($this->root.'/ddev/.ddev/config.zz-server.yaml', "# server: {$server}\nwebserver_type: {$webserverType}\n");
    };
    $this->run = function (string $script, string ...$args): Process {
        return ($this->runWith)([], $script, ...$args);
    };
    $this->runWith = function (array $env, string $script, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('bench/'.$script), ...$args],
            $this->root,
            $env + [
                'BENCH_PARITY_DIR' => $this->root.'/parity',
                'BASE_URL' => 'http://stub.test',
                'PARITY_BASE_URL' => 'http://stub.test',
                'DDEV_APPROOT' => $this->root.'/ddev',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->run();

        return $process;
    };
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-8 capture writes a snapshot per server', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('nginx-fpm');
    $process = ($this->run)('parity.sh', 'capture');
    $snapshot = $this->root.'/parity/nginx-fpm.txt';

    expect($process->getExitCode())->toBe(0)
        ->and(file_exists($snapshot))->toBeTrue()
        ->and(file_get_contents($snapshot))->toContain('/up', '/api/ping', '/listings', '/listings/1')
        ->and(file_get_contents($snapshot))->toMatch('/[0-9a-f]{64}/');
});

it('AC-9 compare exits 0 when identical and 1 with the path when different', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('nginx-fpm');
    ($this->run)('parity.sh', 'capture');
    ($this->setServer)('apache-fpm');
    ($this->run)('parity.sh', 'capture');
    $same = ($this->run)('parity.sh', 'compare', 'nginx-fpm', 'apache-fpm');

    expect($same->getExitCode())->toBe(0)
        ->and($same->getOutput())->toContain('identical');

    ($this->writeCurl)('-changed');
    ($this->run)('parity.sh', 'capture');
    $different = ($this->run)('parity.sh', 'compare', 'nginx-fpm', 'apache-fpm');

    expect($different->getExitCode())->toBe(1)
        ->and($different->getOutput().$different->getErrorOutput())->toContain($this->root.'/parity/apache-fpm.txt');
});

it('AC-11 versions prints one markdown row', function (): void {
    ($this->writeCurl)();
    ($this->writeDdev)('nginx-fpm');
    ($this->setServer)('nginx-fpm');
    $process = ($this->run)('versions.sh');

    expect($process->getExitCode())->toBe(0)
        ->and(trim($process->getOutput()))->toMatch('/^\| nginx-fpm \| 1\.26\.3 \| 1\.25\.4 \| 8\.4\.1 \| fpm-fcgi \| true \| \d{4}-\d\d-\d\d \|$/');
});

it('AC-11 versions uses apache2 -v when apache-fpm is active', function (): void {
    ($this->writeCurl)();
    ($this->writeDdev)('apache-fpm');
    ($this->setServer)('apache-fpm');
    $process = ($this->run)('versions.sh');

    expect($process->getExitCode())->toBe(0)
        ->and(trim($process->getOutput()))->toMatch('/^\| apache-fpm \| 2\.4\.68 \| 1\.25\.4 \| 8\.4\.1 \| fpm-fcgi \| true \| \d{4}-\d\d-\d\d \|$/');
});

it('AC-11 versions exits 1 with a hint when the endpoint is 404', function (): void {
    ($this->writeCurl)('', '404');
    ($this->writeDdev)('nginx-fpm');
    ($this->setServer)('nginx-fpm');
    $process = ($this->run)('versions.sh');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('DEBUG_ROUTES')
        ->and($process->getErrorOutput())->toContain('optimize:clear');
});

it('AC-7 frankenphp capture expects a Caddy server header', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('frankenphp');
    $accepted = ($this->runWith)(['STUB_SERVER_HEADER' => 'Caddy'], 'parity.sh', 'capture');

    expect($accepted->getExitCode())->toBe(0)
        ->and(file_exists($this->root.'/parity/frankenphp.txt'))->toBeTrue();

    unlink($this->root.'/parity/frankenphp.txt');
    $rejected = ($this->runWith)(['STUB_SERVER_HEADER' => 'nginx/1.26.3'], 'parity.sh', 'capture');

    expect($rejected->getExitCode())->toBe(4)
        ->and($rejected->getErrorOutput())->toContain('Snapshot not written')
        ->and(file_exists($this->root.'/parity/frankenphp.txt'))->toBeFalse();
});

it('AC-9 versions parses the FrankenPHP version', function (): void {
    ($this->writeCurl)();
    ($this->writeDdev)('frankenphp');
    ($this->setServer)('frankenphp');
    $process = ($this->runWith)(['STUB_SAPI' => 'frankenphp'], 'versions.sh');

    expect($process->getExitCode())->toBe(0)
        ->and(trim($process->getOutput()))->toMatch('/^\| frankenphp \| 1\.12\.1 \| 1\.25\.4 \| 8\.4\.1 \| frankenphp \| true \| \d{4}-\d\d-\d\d \|$/');
});

it('AC-9 render time is masked in the /up body only', function (): void {
    ($this->writeCurl)(' Response rendered in 5ms.');
    ($this->setServer)('nginx-fpm');
    ($this->run)('parity.sh', 'capture');
    ($this->writeCurl)(' Response rendered in 13ms.');
    ($this->setServer)('apache-fpm');
    ($this->run)('parity.sh', 'capture');
    $process = ($this->run)('parity.sh', 'compare', 'nginx-fpm', 'apache-fpm');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('Differs: /api/ping')
        ->and($process->getOutput())->not->toContain('Differs: /up');
});

it('AC-9 compare ignores content type parameters', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('nginx-fpm');
    ($this->runWith)(['STUB_CONTENT_TYPE' => 'application/json; charset=utf-8'], 'parity.sh', 'capture');
    ($this->setServer)('apache-fpm');
    ($this->runWith)(['STUB_CONTENT_TYPE' => 'application/json'], 'parity.sh', 'capture');

    expect(($this->run)('parity.sh', 'compare', 'nginx-fpm', 'apache-fpm')->getExitCode())->toBe(0);
});

it('AC-8 a failed request leaves the existing snapshot untouched', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('nginx-fpm');
    ($this->run)('parity.sh', 'capture');
    $before = file_get_contents($this->root.'/parity/nginx-fpm.txt');

    $process = ($this->runWith)(['STUB_CURL_FAIL' => '1'], 'parity.sh', 'capture');

    expect($process->getExitCode())->toBe(3)
        ->and($process->getErrorOutput())->toContain('Snapshot not written')
        ->and(file_get_contents($this->root.'/parity/nginx-fpm.txt'))->toBe($before);
});

it('AC-8 capture refuses when the Server header does not match the active server', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('nginx-fpm');
    $process = ($this->runWith)(['STUB_SERVER_HEADER' => 'Apache/2.4.68'], 'parity.sh', 'capture');

    expect($process->getExitCode())->toBe(4)
        ->and(file_exists($this->root.'/parity/nginx-fpm.txt'))->toBeFalse();

    expect(($this->runWith)(['STUB_SERVER_HEADER' => 'nginx/1.26.3'], 'parity.sh', 'capture')->getExitCode())->toBe(0);
});

it('AC-11 versions explains an unreachable site and honours PARITY_INSECURE', function (): void {
    ($this->writeCurl)();
    ($this->writeDdev)('nginx-fpm');
    ($this->setServer)('nginx-fpm');
    $failed = ($this->runWith)(['STUB_CURL_FAIL' => '1'], 'versions.sh');

    expect($failed->getExitCode())->toBe(1)
        ->and($failed->getErrorOutput())->toContain('Cannot reach');

    ($this->run)('versions.sh');
    expect(file_get_contents($this->root.'/curl.log'))->not->toContain(' -k');

    ($this->runWith)(['PARITY_INSECURE' => '1'], 'versions.sh');
    expect(file_get_contents($this->root.'/curl.log'))->toContain(' -k');
});

it('AC-8 capture writes the header line and the seven requests in spec order', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('apache-fpm');
    $process = ($this->run)('parity.sh', 'capture');
    $lines = explode("\n", trim(file_get_contents($this->root.'/parity/apache-fpm.txt')));

    expect($process->getExitCode())->toBe(0)
        ->and($lines[0])->toMatch('/^# server=apache-fpm base=http:\/\/stub\.test captured=\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/')
        ->and($lines)->toHaveCount(8);

    $paths = [];
    foreach (array_slice($lines, 1) as $line) {
        $columns = explode("\t", $line);
        expect($columns)->toHaveCount(4)
            ->and($columns[1])->toBe('200')
            ->and($columns[2])->toBe('application/json')
            ->and($columns[3])->toMatch('/^[0-9a-f]{64}$/');
        $paths[] = $columns[0];
    }

    expect($paths)->toBe([
        '/up',
        '/api/ping',
        '/listings',
        '/listings?make=BMW&fuel=diesel&per_page=5',
        '/listings?per_page=99',
        '/listings/1',
        '/listings/999999',
    ]);
});

it('AC-8 capture sends no Accept header, no redirect following and no -k by default', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('nginx-fpm');
    ($this->run)('parity.sh', 'capture');
    $log = file_get_contents($this->root.'/curl.log');

    expect($log)->not->toContain('Accept')
        ->and($log)->not->toContain(' -k')
        ->and($log)->not->toContain('-L')
        ->and($log)->toContain('http://stub.test/up');
});

it('AC-8 capture adds -k only when PARITY_INSECURE is 1', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('nginx-fpm');
    ($this->runWith)(['PARITY_INSECURE' => '1'], 'parity.sh', 'capture');

    expect(file_get_contents($this->root.'/curl.log'))->toContain('-k');
});

it('AC-8 capture honours PARITY_BASE_URL and defaults to the DDEV site', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('nginx-fpm');
    ($this->runWith)(['PARITY_BASE_URL' => 'http://other.test'], 'parity.sh', 'capture');

    expect(file_get_contents($this->root.'/curl.log'))->toContain('http://other.test/up')
        ->and(file_get_contents($this->root.'/parity/nginx-fpm.txt'))->toContain('base=http://other.test');

    unlink($this->root.'/curl.log');
    $env = ['PARITY_BASE_URL' => false, 'BASE_URL' => false];
    ($this->runWith)($env, 'parity.sh', 'capture');

    expect(file_get_contents($this->root.'/curl.log'))->toContain('https://interview.ddev.site/up');
});

it('AC-8 capture records the lowercased media type without parameters', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('nginx-fpm');
    ($this->runWith)(['STUB_CONTENT_TYPE' => ' Text/HTML; Charset=UTF-8 '], 'parity.sh', 'capture');

    expect(file_get_contents($this->root.'/parity/nginx-fpm.txt'))->toContain("\t200\ttext/html\t");
});

it('AC-9 compare reports identical when only the header lines differ', function (): void {
    $data = "/up\t200\ttext/html\taaa\n/api/ping\t200\tapplication/json\tbbb\n";
    file_put_contents($this->root.'/parity/a.txt', "# server=a captured=2026-01-01T00:00:00Z\n".$data);
    file_put_contents($this->root.'/parity/b.txt', "# server=b captured=2026-02-02T00:00:00Z\n".$data);
    $process = ($this->run)('parity.sh', 'compare', 'a', 'b');

    expect($process->getExitCode())->toBe(0)
        ->and(trim($process->getOutput()))->toBe('identical');
});

it('AC-9 compare prints the differing path with both lines and exits 1', function (): void {
    file_put_contents($this->root.'/parity/a.txt', "# h\n/up\t200\ttext/html\taaa\n/api/ping\t200\tapplication/json\tbbb\n");
    file_put_contents($this->root.'/parity/b.txt', "# h\n/up\t200\ttext/html\taaa\n/api/ping\t500\tapplication/json\tccc\n");
    $process = ($this->run)('parity.sh', 'compare', 'a', 'b');
    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(1)
        ->and($output)->toContain('/api/ping')
        ->and($output)->toContain("/api/ping\t200\tapplication/json\tbbb")
        ->and($output)->toContain("/api/ping\t500\tapplication/json\tccc")
        ->and($output)->not->toContain('identical');
});

it('AC-9 compare exits 1 when the line counts differ', function (): void {
    file_put_contents($this->root.'/parity/a.txt', "# h\n/up\t200\ttext/html\taaa\n/api/ping\t200\tapplication/json\tbbb\n");
    file_put_contents($this->root.'/parity/b.txt', "# h\n/up\t200\ttext/html\taaa\n");
    $process = ($this->run)('parity.sh', 'compare', 'a', 'b');
    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(1)
        ->and($output)->toContain('/api/ping')
        ->and($output)->toContain('Line count');
});

it('AC-9 compare exits 2 and names a missing snapshot', function (): void {
    file_put_contents($this->root.'/parity/a.txt', "/up\t200\ttext/html\taaa\n");
    $process = ($this->run)('parity.sh', 'compare', 'a', 'missing');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain($this->root.'/parity/missing.txt');
});

it('AC-9 compare reads an argument containing a slash as a path', function (): void {
    mkdir($this->root.'/x');
    file_put_contents($this->root.'/x/nginx-fpm.txt', "# a\n/up\t200\ttext/html\taaa\n");
    file_put_contents($this->root.'/x/other.txt', "# b\n/up\t200\ttext/html\taaa\n");
    $same = ($this->run)('parity.sh', 'compare', './x/nginx-fpm.txt', $this->root.'/x/other.txt');
    $missing = ($this->run)('parity.sh', 'compare', './x/nginx-fpm.txt', './x/nope.txt');

    expect($same->getExitCode())->toBe(0)
        ->and($same->getOutput())->toContain('identical')
        ->and($missing->getExitCode())->toBe(2)
        ->and($missing->getErrorOutput())->toContain('./x/nope.txt');
});

it('AC-10 committed snapshots use the spec format and cover the seven requests', function (): void {
    $paths = ['/up', '/api/ping', '/listings', '/listings?make=BMW&fuel=diesel&per_page=5', '/listings?per_page=99', '/listings/1', '/listings/999999'];

    foreach (['nginx-fpm', 'apache-fpm'] as $server) {
        $lines = file(base_path("bench/parity/{$server}.txt"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        expect($lines[0])->toMatch('/^# server='.$server.' base=https:\/\/interview\.ddev\.site captured=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

        $rows = array_map(fn (string $line): array => explode("\t", $line), array_slice($lines, 1));
        expect(array_column($rows, 0))->toBe($paths);

        foreach ($rows as $row) {
            expect($row)->toHaveCount(4);
            expect($row[3])->toMatch('/^[0-9a-f]{64}$/');
        }

        expect($rows[4][1])->toBe('422')->and($rows[6][1])->toBe('404');
    }
});

it('AC-8 committed frankenphp snapshot matches both FPM snapshots', function (): void {
    $rows = fn (string $server): array => array_values(array_filter(
        file(base_path("bench/parity/{$server}.txt"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        fn (string $line): bool => ! str_starts_with($line, '#'),
    ));

    expect(file_exists(base_path('bench/parity/frankenphp.txt')))->toBeTrue()
        ->and(file(base_path('bench/parity/frankenphp.txt'))[0])->toStartWith('# server=frankenphp base=https://interview.ddev.site captured=')
        ->and($rows('frankenphp'))->toHaveCount(7)
        ->and($rows('frankenphp'))->toBe($rows('nginx-fpm'))
        ->and($rows('frankenphp'))->toBe($rows('apache-fpm'));
});

it('AC-7 capture refuses a server missing from the token map', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('octane-frankenphp');
    $process = ($this->runWith)(['STUB_SERVER_HEADER' => 'Caddy'], 'parity.sh', 'capture');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('header_token')
        ->and(file_exists($this->root.'/parity/octane-frankenphp.txt'))->toBeFalse();
});

it('AC-7 capture refuses a response without a Server header', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('nginx-fpm');
    $process = ($this->runWith)(['STUB_SERVER_HEADER' => ''], 'parity.sh', 'capture');

    expect($process->getExitCode())->toBe(4)
        ->and($process->getErrorOutput())->toContain('<missing>')
        ->and(file_exists($this->root.'/parity/nginx-fpm.txt'))->toBeFalse();
});

it('AC-9 versions refuses a server it has no version command for', function (): void {
    ($this->writeCurl)();
    ($this->writeDdev)('octane-frankenphp');
    ($this->setServer)('octane-frankenphp');
    $process = ($this->run)('versions.sh');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('No web server version command')
        ->and($process->getOutput())->toBe('');
});

it('AC-7 openlitespeed capture defaults to port 8091 and expects a LiteSpeed header', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('openlitespeed');
    $env = ['PARITY_BASE_URL' => false, 'BASE_URL' => false];
    $accepted = ($this->runWith)($env + ['STUB_SERVER_HEADER' => 'LiteSpeed'], 'parity.sh', 'capture');

    expect($accepted->getExitCode())->toBe(0)
        ->and(file_get_contents($this->root.'/curl.log'))->toContain('https://interview.ddev.site:8091/up')
        ->and(file_get_contents($this->root.'/parity/openlitespeed.txt'))->toContain('base=https://interview.ddev.site:8091');

    unlink($this->root.'/parity/openlitespeed.txt');
    $rejected = ($this->runWith)($env + ['STUB_SERVER_HEADER' => 'nginx/1.26.3'], 'parity.sh', 'capture');

    expect($rejected->getExitCode())->toBe(4)
        ->and(file_exists($this->root.'/parity/openlitespeed.txt'))->toBeFalse();
});

it('AC-7 PARITY_BASE_URL overrides the openlitespeed default', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('openlitespeed');
    $process = ($this->runWith)(['PARITY_BASE_URL' => 'http://other.test'], 'parity.sh', 'capture');

    expect($process->getExitCode())->toBe(0)
        ->and(file_get_contents($this->root.'/curl.log'))->toContain('http://other.test/up')
        ->and(file_get_contents($this->root.'/curl.log'))->not->toContain(':8091');
});

it('AC-7 other servers keep the plain DDEV default URL', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('frankenphp');
    ($this->runWith)(['PARITY_BASE_URL' => false, 'BASE_URL' => false, 'STUB_SERVER_HEADER' => 'Caddy'], 'parity.sh', 'capture');

    expect(file_get_contents($this->root.'/curl.log'))->toContain('https://interview.ddev.site/up')
        ->and(file_get_contents($this->root.'/curl.log'))->not->toContain(':8091');
});

it('AC-7 capture rewrites the base URL in the body before hashing', function (): void {
    ($this->writeCurl)();
    ($this->setServer)('openlitespeed');
    ($this->runWith)(['PARITY_BASE_URL' => 'http://other.test'], 'parity.sh', 'capture');
    $line = collect(file($this->root.'/parity/openlitespeed.txt', FILE_IGNORE_NEW_LINES))
        ->first(fn (string $row): bool => str_starts_with($row, "/api/ping\t"));

    expect(explode("\t", $line)[3])->toBe(hash('sha256', 'body:https://interview.ddev.site/api/ping'));
});

it('AC-9 versions parses the OpenLiteSpeed version', function (): void {
    ($this->writeCurl)();
    ($this->writeDdev)('openlitespeed');
    ($this->setServer)('openlitespeed');
    $process = ($this->runWith)(['STUB_SAPI' => 'litespeed'], 'versions.sh');

    expect($process->getExitCode())->toBe(0)
        ->and(trim($process->getOutput()))->toMatch('/^\| openlitespeed \| 1\.9\.4 \| 1\.25\.4 \| 8\.4\.1 \| litespeed \| true \| \d{4}-\d\d-\d\d \|$/');
});

it('AC-7 versions requests /debug/runtime on the openlitespeed port by default', function (): void {
    ($this->writeCurl)();
    ($this->writeDdev)('openlitespeed');
    ($this->setServer)('openlitespeed');
    ($this->runWith)(['PARITY_BASE_URL' => false, 'BASE_URL' => false], 'versions.sh');

    expect(file_get_contents($this->root.'/curl.log'))->toContain('https://interview.ddev.site:8091/debug/runtime');
});

it('AC-8 committed openlitespeed snapshot matches nginx-fpm', function (): void {
    $rows = fn (string $server): array => array_values(array_filter(
        file(base_path("bench/parity/{$server}.txt"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        fn (string $line): bool => ! str_starts_with($line, '#'),
    ));

    expect(file_exists(base_path('bench/parity/openlitespeed.txt')))->toBeTrue()
        ->and(file(base_path('bench/parity/openlitespeed.txt'))[0])->toStartWith('# server=openlitespeed base=https://interview.ddev.site:8091 captured=')
        ->and($rows('openlitespeed'))->toHaveCount(7)
        ->and($rows('openlitespeed'))->toBe($rows('nginx-fpm'));
});
