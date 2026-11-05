<?php

declare(strict_types=1);

/**
 * @return array<int, array<string, mixed>>
 */
function committedResults(string $kind, string $name): array
{
    $files = glob(base_path("bench/results/$kind/*/$name.json")) ?: [];

    return array_map(fn (string $file): array => json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), $files);
}

test('AC-3 the 103 path is recorded for frankenphp and nginx-fpm', function (): void {
    foreach (['frankenphp' => true, 'nginx-fpm' => false] as $server => $expected) {
        $results = committedResults('early-hints', $server);

        expect($results)->not->toBeEmpty();
        expect($results[0]['direct_103'])->toBe($expected)
            ->and($results[0]['router_103'])->toBe($expected);
    }
});

test('AC-2 the worker and Octane servers send no 103, on either path', function (): void {
    foreach (['frankenphp-worker', 'frankenphp-octane'] as $server) {
        $results = committedResults('early-hints', $server);

        expect($results)->not->toBeEmpty()
            ->and($results[0]['router_103'])->toBeFalse()
            ->and($results[0]['direct_103'])->toBeFalse();
    }
});

test('committed bench results hold no certificate lines, internal IPs, user name or host name', function (): void {
    $files = array_merge(
        glob(base_path('bench/results/early-hints/*/*')) ?: [],
        glob(base_path('bench/results/http3/*/*')) ?: [],
        glob(base_path('bench/results/lcp/*/*.json')) ?: [],
        glob(base_path('bench/results/lcp/*/*/*.json')) ?: [],
        glob(base_path('bench/results/compression/*/*.json')) ?: [],
    );

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $text = file_get_contents($file);

        expect(preg_match('/stasmarkov|Stass-MacBook|subject:|issuer:|mkcert|OU=|\b\d{1,3}(\.\d{1,3}){3}\b|\p{Cyrillic}/iu', $text))->toBe(0, $file);
    }
});

test('AC-4 LCP has 3 variants of 5 cold runs, early hint used only with frankenphp hints on', function (): void {
    $expected = ['frankenphp-hints' => true, 'frankenphp-no-hints' => false, 'nginx-fpm' => false];

    foreach ($expected as $variant => $hintUsed) {
        $results = committedResults('lcp', $variant);

        expect($results)->not->toBeEmpty();
        $result = $results[0];
        expect($result['runs'])->toBe(5)
            ->and($result['samples'])->toHaveCount(5)
            ->and($result['throttle'])->toMatchArray(['rtt_ms' => 150, 'down_kbps' => 1600, 'up_kbps' => 750, 'cpu_slowdown' => 4])
            ->and($result['throttle']['method'])->toContain('toxiproxy')
            ->and($result['throttle_image_digest'])->toContain('@sha256:')
            ->and($result['valid_runs'])->toBeGreaterThanOrEqual(3)
            ->and($result['valid_runs'] + $result['invalid_runs'])->toBe(5)
            ->and($result['path_reason'])->not->toBeEmpty();
        foreach (['lcp_ms', 'fcp_ms', 'ttfb_ms'] as $metric) {
            expect($result[$metric])->toHaveKeys(['median', 'min', 'max']);
        }
        expect($result['early_hint_used'])->toBe($hintUsed)
            ->and($result['cold'])->toBeTrue()
            ->and($result['ttfb_ms']['median'])->toBeGreaterThanOrEqual(150)
            ->and($result['ttfb_source'])->toContain('Network.responseReceived');
    }
});

test('AC-5 compression is recorded per encoding and resource on both servers', function (): void {
    foreach (['nginx-fpm', 'frankenphp'] as $server) {
        $results = committedResults('compression', $server);

        expect($results)->not->toBeEmpty();
        $accepted = array_unique(array_column($results[0]['resources'], 'accept_encoding'));
        expect($accepted)->toContain('identity', 'gzip', 'br', 'zstd', 'zstd, br, gzip')
            ->and(array_unique(array_column($results[0]['resources'], 'resource')))->toContain('html', 'css', 'page-js', 'lab-bundle', 'export')
            ->and(array_unique(array_column($results[0]['resources'], 'resource')))->not->toContain('js');
    }
});

test('AC-6 the committed export is compressed at most once and matches the file hash', function (): void {
    foreach (['nginx-fpm', 'frankenphp'] as $server) {
        $export = committedResults('compression', $server)[0]['export'] ?? null;

        expect($export)->not->toBeNull()
            ->and($export['ok'])->toBeTrue()
            ->and(array_column($export['rows'], 'single_encoding'))->each->toBeTrue()
            ->and(array_column($export['rows'], 'sha_ok'))->each->toBeTrue();
    }
});

test('AC-2 the final 200 headers on frankenphp differ with hints on and off only by the Server header, and the deviation is documented', function (): void {
    $dir = base_path('bench/results/early-hints/2026-11-05');
    $headers = fn (string $state): array => array_values(array_filter(array_map(
        trim(...),
        file($dir."/frankenphp-final-headers-hints-$state.txt") ?: [],
    ), fn (string $line): bool => $line !== ''));

    expect($headers('on'))->toContain('HTTP/2 200')
        ->and($headers('off'))->toContain('HTTP/2 200');

    $onlyOff = array_values(array_diff($headers('off'), $headers('on')));
    expect($onlyOff)->toBe(['server: FrankenPHP Caddy'])
        ->and(array_diff($headers('on'), $headers('off')))->toBe([]);

    $spec = file_get_contents(base_path('.ai/specs/spec-20261104-2340-early-hints-compression/spec-lite.md'));
    $benchmarks = file_get_contents(base_path('BENCHMARKS.md'));

    expect($spec)->toContain('**Final 200 headers on FrankenPHP (Q2 deviation).**')
        ->and($benchmarks)->toContain('**The Server header is missing from the final 200 when a 103 is sent.**');
});

test('AC-4 NFR-1 the HTTP/3 run has 4 netem variants of 5 cold runs, each on its own protocol, with one recorded setup and a measured RTT', function (): void {
    $expected = ['h2-loss0' => ['h2', 0], 'h3-loss0' => ['h3', 0], 'h2-loss2' => ['h2', 2], 'h3-loss2' => ['h3', 2]];
    $profiles = [];
    $digests = [];
    $offloads = [];

    foreach ($expected as $variant => [$protocol, $loss]) {
        $results = committedResults('http3', 'lcp-'.$variant);

        expect($results)->not->toBeEmpty();
        $result = $results[0];
        expect($result['protocol'])->toBe($protocol)
            ->and($result['variant'])->toBe($variant)
            ->and($result['path'])->toBe('direct')
            ->and($result['runs'])->toBe(5)
            ->and($result['attempts'])->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(8)
            ->and($result['valid_runs'])->toBe(5)
            ->and($result['cold'])->toBeTrue()
            ->and($result['server'])->toBe('frankenphp')
            ->and($result['net']['applied'])->toBeTrue()
            ->and($result['net']['rtt_ms'])->toBeGreaterThanOrEqual(140)->toBeLessThanOrEqual(170)
            ->and($result['net']['loss_pct'])->toEqual($loss)
            ->and($result['net']['sidecar_image_digest'])->toMatch('/^sha256:[0-9a-f]{64}$/')
            ->and($result['throttle']['method'])->toContain('netem')
            ->and(array_column($result['samples'], 'next_hop_protocol'))->each->toBeString();
        foreach (array_filter($result['samples'], fn (array $sample): bool => $sample['valid']) as $sample) {
            expect($sample['next_hop_protocol'])->toBe($protocol);
        }
        foreach (['lcp_ms', 'fcp_ms', 'ttfb_ms', 'load_ms'] as $metric) {
            expect($result[$metric])->toHaveKeys(['median', 'min', 'max']);
        }
        $profiles[$variant] = array_intersect_key($result['net'], array_flip(['delay_ms', 'loss_pct', 'rate_up', 'rate_down']));
        $digests[$variant] = $result['net']['sidecar_image_digest'];
        $offloads[$variant] = $result['net']['offloads'];
    }

    expect($profiles['h2-loss0'])->toEqual($profiles['h3-loss0'])
        ->and($profiles['h2-loss2'])->toEqual($profiles['h3-loss2'])
        ->and($profiles['h2-loss0']['delay_ms'])->toBe($profiles['h2-loss2']['delay_ms'])
        ->and(array_unique($digests))->toHaveCount(1);
    foreach ($offloads as $variant => $state) {
        expect($state['client']['before']['eth0'])->toHaveKeys(['tso', 'gso', 'gro', 'udp_gso'])
            ->and($state['client']['after']['eth0'])->toMatchArray(['tso' => 'off', 'gso' => 'off', 'gro' => 'off'])
            ->and($state['web']['after']['eth0'])->toMatchArray(['tso' => 'off', 'gso' => 'off', 'gro' => 'off', 'udp_gso' => 'off'])
            ->and($state['web']['restored'])->toBe($state['web']['before'])
            ->and($state['web']['before']['eth0'])->toEqual($offloads['h2-loss0']['web']['before']['eth0']);
    }
});

test('AC-2 NFR-1 every netem file has 20 RTT samples and per-packet qdisc counters, and the loss-2 files drop 1-3.5 percent in both directions', function (): void {
    foreach (['h2-loss0', 'h3-loss0', 'h2-loss2', 'h3-loss2'] as $variant) {
        $net = committedResults('http3', 'lcp-'.$variant)[0]['net'];

        expect($net)->toMatchArray(['mode' => 'bidirectional', 'limit' => 1000])
            ->and($net['qdisc'])->toHaveKeys(['egress_drop_rate', 'ingress_drop_rate', 'egress_packets', 'egress_dropped', 'ingress_packets', 'ingress_dropped'])
            ->and($net['qdisc']['unit'])->toContain('packets')
            ->and($net['qdisc']['egress_packets'])->toBeGreaterThan(100)
            ->and($net['qdisc']['ingress_packets'])->toBeGreaterThan(100)
            ->and($net['rtt_samples_ms'])->toHaveCount(20);
        if ($net['loss_pct'] > 0) {
            $window = [$net['loss_pct'] * 0.5, $net['loss_pct'] * 1.75];
            expect($net['qdisc']['accepted_drop_pct'])->toEqual($window)
                ->and($net['qdisc']['egress_drop_rate'])->toBeGreaterThanOrEqual($window[0])->toBeLessThanOrEqual($window[1])
                ->and($net['qdisc']['ingress_drop_rate'])->toBeGreaterThanOrEqual($window[0])->toBeLessThanOrEqual($window[1])
                ->and($net['qdisc']['egress_dropped'])->toBeGreaterThan(0)
                ->and($net['qdisc']['ingress_dropped'])->toBeGreaterThan(0);
        } else {
            expect($net['qdisc']['egress_dropped'])->toBe(0)->and($net['qdisc']['ingress_dropped'])->toBe(0);
        }
    }
});

test('AC-1 the committed http3.sh run shows h3 on frankenphp with alpn_h2 h2 and a reasoned h3_ok false on nginx-fpm, and no raw body in the results', function (): void {
    $franken = committedResults('http3', 'frankenphp')[0];
    $nginx = committedResults('http3', 'nginx-fpm')[0];

    expect($franken['h3_ok'])->toBeTrue()
        ->and($franken['path'])->toBe('/listings/1')
        ->and($franken['h3_status'])->toBe(200)
        ->and($franken['h2_status'])->toBe(200)
        ->and($franken['alpn_h2'])->toBe('h2')
        ->and($franken)->not->toHaveKey('h2_alpn')
        ->and($franken['alt_svc'])->toContain('h3=":443"')
        ->and($franken['early_hints_during_h3'])->toBeFalse()
        ->and($franken['early_hints_reason'])->toContain('103')
        ->and($franken['curl_image_digest'])->toMatch('/@sha256:[0-9a-f]{64}$/')
        ->and($franken['body_sha256_h3'])->toBe($franken['body_sha256_h2'])
        ->and($nginx['h3_ok'])->toBeFalse()
        ->and($nginx['h2_status'])->toBe(200)
        ->and($nginx['reason'])->not->toBeEmpty();
    expect(glob(base_path('bench/results/http3/*/*.html')) ?: [])->toBe([]);
});
