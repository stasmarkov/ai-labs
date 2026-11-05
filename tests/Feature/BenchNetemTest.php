<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-netem-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);

    file_put_contents($this->root.'/bin/docker', <<<'BASH'
#!/usr/bin/env bash
echo "docker $*" >> "$STUB_LOG"
args="$*"
if [[ "$args" == *"ethtool -k"* ]]; then
  devs=$(echo "$args" | head -n1 | sed -E 's/.*-e DEVS=(.*) -e RESTORE=.*/\1/')
  state=on
  [[ -f "$STUB_LOG.off" ]] && state=off
  for d in $devs; do echo "$d tso=$state gso=$state gro=off udp_gso=$state"; done
  exit 0
fi
if [[ "$args" == *"offloads_off"* && -z "${STUB_OFFLOADS_STUCK-}" ]]; then touch "$STUB_LOG.off"; fi
if [[ "$args" == *"ethtool -K"* && "$args" == *"udp_gso"* ]]; then rm -f "$STUB_LOG.off"; fi
if [[ -n "${STUB_IFB_FAIL-}" && "$args" == *"ip link add ifb0"* ]]; then
  echo "RTNETLINK answers: Operation not supported" >&2
  exit 42
fi
if [[ "$args" == *"netem delay"*"ingress"* ]]; then
  [[ -n "${STUB_APPLY_ERR-}" ]] && { echo "$STUB_APPLY_ERR" >&2; exit 2; }
  exit 0
elif [[ "$args" == *"tc -s qdisc show"* ]]; then
  read -r e_sent e_drop <<< "${STUB_DROPS_EGRESS-1000 20}"
  read -r i_sent i_drop <<< "${STUB_DROPS_INGRESS-2000 41}"
  echo "qdisc netem 8001: root refcnt 2 limit 1000 delay 75ms loss 2% rate 750Kbit"
  echo " Sent 100000 bytes $e_sent pkt (dropped $e_drop, overlimits 0 requeues 0)"
  echo " backlog 0b 0p requeues 0"
  echo "qdisc ingress ffff: parent ffff:fff1 ----------------"
  echo " Sent 9 bytes 9 pkt (dropped 0, overlimits 0 requeues 0)"
  echo "--ifb0--"
  echo "qdisc netem 8002: root refcnt 2 limit 1000 delay 75ms loss 2% rate 1600Kbit"
  echo " Sent 200000 bytes $i_sent pkt (dropped $i_drop, overlimits 0 requeues 0)"
elif [[ "$args" == *"tc qdisc show"* ]]; then
  if [[ "$args" == *"DELAY=150ms"* ]]; then
    echo "qdisc netem 8001: root refcnt 2 limit 1000 delay 150ms loss 2%"
  elif [[ -n "${STUB_NO_NETEM-}" ]]; then
    echo "qdisc noqueue 0: root refcnt 2"
  else
    echo "qdisc netem 8001: root refcnt 2 limit 1000 delay 75ms loss 2% rate 750Kbit"
    echo "qdisc netem 8002: root refcnt 2 limit 1000 delay 75ms loss 2% rate 1600Kbit"
  fi
elif [[ "$args" == *"time_connect"* ]]; then
  for s in ${STUB_RTT-0.1538 0.1547 1.1616 0.1524 0.1528}; do echo "$s"; done
fi
BASH);
    chmod($this->root.'/bin/docker', 0755);

    $this->netem = function (array $env, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('bench/lib/netem.sh'), ...$args],
            $this->root,
            $env + [
                'STUB_LOG' => $this->root.'/calls.log',
                'NETEM_PROBE_URL' => 'https://interview.ddev.site/up',
                'NETEM_STATE_DIR' => $this->root,
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

it('AC-2 netem shapes egress and ifb ingress from a NET_ADMIN sidecar in the client netns and verifies the median RTT', function (): void {
    $process = ($this->netem)([], 'apply', 'client-1');
    $calls = file_get_contents($this->root.'/calls.log');
    $profile = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('--cap-add NET_ADMIN', '--network container:client-1', 'netem delay "$DELAY" loss "$LOSS" rate "$RATE_UP"', 'ip link add ifb0 type ifb', 'action mirred egress redirect dev ifb0', 'rate "$RATE_DOWN"')
        ->and($profile)->toMatchArray([
            'applied' => true, 'reason' => null, 'delay_ms' => 75, 'loss_pct' => 2,
            'rate_up' => '750kbit', 'rate_down' => '1600kbit', 'rtt_ms' => 154,
            'rtt_samples_ms' => [154, 155, 1162, 152, 153], 'rtt_target_ms' => [140, 170],
        ]);
});

it('AC-2 netem falls back with a reason and removes the shaping when the RTT is off', function (): void {
    $process = ($this->netem)(['STUB_RTT' => '0.021 0.020 0.022'], 'apply', 'client-1');
    $profile = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($profile['applied'])->toBeFalse()
        ->and($profile['rtt_ms'])->toBe(21)
        ->and($profile['reason'])->toBe('measured RTT 21 ms outside 140-170 ms')
        ->and(file_get_contents($this->root.'/calls.log'))->toContain('tc qdisc del dev "$DEV" root');
});

it('AC-2 netem falls back with the sidecar error as reason, without addresses, when tc cannot shape', function (): void {
    $process = ($this->netem)(['STUB_APPLY_ERR' => 'RTNETLINK answers: Operation not permitted 10.0.0.5'], 'apply', 'client-1');
    $profile = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($profile['applied'])->toBeFalse()
        ->and($profile['rtt_ms'])->toBeNull()
        ->and($profile['reason'])->toBe('netem unavailable: RTNETLINK answers: Operation not permitted <ip>')
        ->and($process->getOutput())->not->toContain('10.0.0.5');
});

it('AC-2 netem verify fails when the qdiscs carry no netem and does not need apply', function (): void {
    $process = ($this->netem)(['STUB_NO_NETEM' => '1'], 'verify', 'client-1');
    $profile = json_decode($process->getOutput(), true);

    expect($profile['applied'])->toBeFalse()
        ->and($profile['reason'])->toBe('netem delay/loss not found on eth0 and ifb0');
});

it('NFR-1 netem profile records the same profile fields for every client and clear removes the shaping', function (): void {
    $profile = json_decode(($this->netem)([], 'profile', 'client-2')->getOutput(), true);
    $clear = ($this->netem)([], 'clear', 'client-2');

    expect($profile)->toHaveKeys(['applied', 'delay_ms', 'loss_pct', 'rate_up', 'rate_down', 'rtt_ms', 'rtt_samples_ms'])
        ->and($profile['applied'])->toBeTrue()
        ->and($clear->getExitCode())->toBe(0)
        ->and(file_get_contents($this->root.'/calls.log'))->toContain('ip link del ifb0');
});

it('AC-2 netem rejects missing arguments with usage and exit 2', function (): void {
    $process = ($this->netem)([], 'apply');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Usage: bench/lib/netem.sh');
});

it('AC-2 NFR-1 netem drops passes when both directions drop 1-3.5 percent of the sent packets and reports mode, limit and rates', function (): void {
    $process = ($this->netem)([], 'drops', 'client-1');
    $profile = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($profile)->toMatchArray(['mode' => 'bidirectional', 'limit' => 1000, 'reason' => null])
        ->and($profile['qdisc'])->toMatchArray([
            'egress_drop_rate' => 2.0, 'ingress_drop_rate' => 2.05, 'egress_packets' => 1000, 'egress_dropped' => 20,
            'ingress_packets' => 2000, 'ingress_dropped' => 41, 'accepted_drop_pct' => [1, 3.5],
        ])
        ->and($profile['qdisc']['unit'])->toContain('packets');
});

it('AC-2 netem drops fails when loss is 2 and no packet was dropped', function (): void {
    $process = ($this->netem)(['STUB_DROPS_EGRESS' => '1000 0'], 'drops', 'client-1');
    $profile = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(1)
        ->and($profile['reason'])->toBe('egress drop rate 0.00% outside 1-3.5% with loss 2%')
        ->and($profile['qdisc']['egress_drop_rate'])->toEqual(0);
});

it('AC-2 netem drops fails when a direction drops 5 percent', function (): void {
    $process = ($this->netem)(['STUB_DROPS_INGRESS' => '1000 50'], 'drops', 'client-1');
    $profile = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(1)
        ->and($profile['reason'])->toBe('ingress drop rate 5.00% outside 1-3.5% with loss 2%');
});

it('AC-2 netem drops does not judge the rates when loss is 0', function (): void {
    $process = ($this->netem)(['NETEM_LOSS' => '0', 'STUB_DROPS_EGRESS' => '1000 0', 'STUB_DROPS_INGRESS' => '1000 0'], 'drops', 'client-1');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('AC-2 netem takes 20 RTT samples by default', function (): void {
    ($this->netem)([], 'apply', 'client-1');

    expect(file_get_contents($this->root.'/calls.log'))->toContain('-e SAMPLES=20 ');
});

it('AC-2 netem apply stops with exit 2 and a message when ifb0 cannot be created, without the fallback flag', function (): void {
    $process = ($this->netem)(['STUB_IFB_FAIL' => '1'], 'apply', 'client-1');
    $profile = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('ifb unavailable', '--netem-fallback egress-only')
        ->and($profile['applied'])->toBeFalse()
        ->and($profile['mode'])->toBe('bidirectional')
        ->and(file_get_contents($this->root.'/calls.log'))->toContain('ip link del ifb0');
});

it('AC-2 netem egress-only fallback shapes egress with delay 150, loss and no rate and records the mode', function (): void {
    $process = ($this->netem)(['STUB_IFB_FAIL' => '1', 'STUB_RTT' => '0.1538 0.1547 0.1524'], '--netem-fallback', 'egress-only', 'apply', 'client-1');
    $profile = json_decode($process->getOutput(), true);
    $calls = file_get_contents($this->root.'/calls.log');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($profile)->toMatchArray(['applied' => true, 'mode' => 'egress-only', 'delay_ms' => 150, 'loss_pct' => 2, 'rate_up' => null, 'rate_down' => null])
        ->and($calls)->toContain('-e DELAY=150ms')
        ->and($calls)->not->toContain('ifb0', 'rate "$RATE_UP"');
});

it('AC-2 netem egress-only drops reads the egress counters only and rejects an unknown fallback', function (): void {
    $drops = ($this->netem)([], '--netem-fallback', 'egress-only', 'drops', 'client-1');
    $unknown = ($this->netem)([], '--netem-fallback', 'nope', 'apply', 'client-1');

    expect($drops->getExitCode())->toBe(0, $drops->getErrorOutput())
        ->and(json_decode($drops->getOutput(), true)['qdisc'])->toMatchArray(['egress_drop_rate' => 2.0, 'ingress_drop_rate' => null, 'ingress_packets' => null])
        ->and($unknown->getExitCode())->toBe(2);
});

it('AC-2 manual test step 2 uses the real netem.sh command line', function (): void {
    $manual = file_get_contents(base_path('.ai/specs/spec-20261105-1109-http3/manual-test.md'));
    preg_match('/## Step 2(.*?)## Step 3/s', $manual, $step);

    expect($step[1])->toContain('bench/lib/netem.sh verify', 'bench/lib/netem.sh --netem-fallback egress-only apply')
        ->and($step[1])->not->toContain('--verify');
});

it('NFR-1 AC-2 netem sidecar runs a digest-pinned image without installing packages and the profile records the digest', function (): void {
    $process = ($this->netem)([], 'apply', 'client-1');
    $calls = file_get_contents($this->root.'/calls.log');
    $profile = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toMatch('/--entrypoint sh \S+@sha256:[0-9a-f]{64} /')
        ->and($calls)->not->toContain('apk add')
        ->and($profile['sidecar_image_digest'])->toMatch('/^sha256:[0-9a-f]{64}$/');
});

it('NFR-1 netem profile records the digest of an overridden NETEM_IMAGE and null for an unpinned tag', function (): void {
    $digest = 'sha256:'.str_repeat('a', 64);
    $pinned = json_decode(($this->netem)(['NETEM_IMAGE' => 'example/tc@'.$digest], 'profile', 'client-1')->getOutput(), true);
    $tagged = json_decode(($this->netem)(['NETEM_IMAGE' => 'example/tc:1'], 'profile', 'client-1')->getOutput(), true);

    expect($pinned['sidecar_image_digest'])->toBe($digest)
        ->and($tagged['sidecar_image_digest'])->toBeNull();
});

it('AC-2 netem drop window follows the loss: 0.5 to 1.75 times the configured percent', function (): void {
    $fiveOk = ($this->netem)(['NETEM_LOSS' => '5', 'STUB_DROPS_EGRESS' => '1000 50', 'STUB_DROPS_INGRESS' => '1000 80'], 'drops', 'client-1');
    $fiveLow = ($this->netem)(['NETEM_LOSS' => '5', 'STUB_DROPS_EGRESS' => '1000 20'], 'drops', 'client-1');
    $oneHigh = ($this->netem)(['NETEM_LOSS' => '1', 'STUB_DROPS_EGRESS' => '1000 10', 'STUB_DROPS_INGRESS' => '1000 25'], 'drops', 'client-1');

    expect($fiveOk->getExitCode())->toBe(0, $fiveOk->getErrorOutput())
        ->and(json_decode($fiveOk->getOutput(), true)['qdisc']['accepted_drop_pct'])->toBe([2.5, 8.75])
        ->and($fiveLow->getExitCode())->toBe(1)
        ->and(json_decode($fiveLow->getOutput(), true)['reason'])->toBe('egress drop rate 2.00% outside 2.5-8.75% with loss 5%')
        ->and($oneHigh->getExitCode())->toBe(1)
        ->and(json_decode($oneHigh->getOutput(), true)['reason'])->toBe('ingress drop rate 2.50% outside 0.5-1.75% with loss 1%');
});

it('AC-2 NFR-1 netem turns tso, gso, gro and UDP GSO off on the client eth0 and ifb0 and records before and after', function (): void {
    $process = ($this->netem)([], 'apply', 'client-1');
    $profile = json_decode($process->getOutput(), true);
    $calls = file_get_contents($this->root.'/calls.log');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('ethtool -K "$1" tso off gso off gro off tx-udp-segmentation off', 'offloads_off "$DEV"', 'offloads_off ifb0')
        ->and($profile['offloads']['client']['before'])->toBe(['eth0' => ['tso' => 'on', 'gso' => 'on', 'gro' => 'off', 'udp_gso' => 'on']])
        ->and($profile['offloads']['client']['after']['eth0'])->toBe(['tso' => 'off', 'gso' => 'off', 'gro' => 'off', 'udp_gso' => 'off'])
        ->and($profile['offloads']['client']['after']['ifb0']['gso'])->toBe('off');
});

it('AC-2 netem refuses to shape when the offloads of eth0 stay on and removes the shaping', function (): void {
    $process = ($this->netem)(['STUB_OFFLOADS_STUCK' => '1'], 'apply', 'client-1');
    $profile = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($profile['applied'])->toBeFalse()
        ->and($profile['reason'])->toBe('cannot turn tso, gso and gro off on eth0')
        ->and(file_get_contents($this->root.'/calls.log'))->toContain('ip link del ifb0');
});

it('AC-2 netem probes the RTT with HEAD requests and resets the qdisc counters after the verification', function (): void {
    ($this->netem)([], 'apply', 'client-1');
    $lines = explode("\n", file_get_contents($this->root.'/calls.log'));
    $probe = array_key_last(array_filter($lines, fn (string $line): bool => str_contains($line, 'time_connect')));
    $reset = array_key_last(array_filter($lines, fn (string $line): bool => str_contains($line, 'tc qdisc add dev ifb0 root netem') && ! str_contains($line, 'ingress')));

    expect(file_get_contents($this->root.'/calls.log'))->toContain('curl -ksI -o /dev/null')
        ->and($probe)->not->toBeNull()
        ->and($reset)->toBeGreaterThan($probe);
});

it('AC-2 netem web-offloads off records the web container before and after, restore puts the old values back and clears the state', function (): void {
    $off = ($this->netem)([], 'web-offloads', 'off', 'ddev-interview-web');
    $offJson = json_decode($off->getOutput(), true);
    $state = $this->root.'/netem-offloads-ddev-interview-web.state';
    $stateExistsAfterOff = file_exists($state);
    $restore = ($this->netem)([], 'web-offloads', 'restore', 'ddev-interview-web');
    $restoreJson = json_decode($restore->getOutput(), true);
    $again = ($this->netem)([], 'web-offloads', 'restore', 'ddev-interview-web');

    expect($off->getExitCode())->toBe(0, $off->getErrorOutput())
        ->and($offJson['before']['eth0'])->toBe(['tso' => 'on', 'gso' => 'on', 'gro' => 'off', 'udp_gso' => 'on'])
        ->and($offJson['after']['eth0'])->toBe(['tso' => 'off', 'gso' => 'off', 'gro' => 'off', 'udp_gso' => 'off'])
        ->and($stateExistsAfterOff)->toBeTrue()
        ->and($restore->getExitCode())->toBe(0, $restore->getErrorOutput())
        ->and($restoreJson)->toBe(['restored' => $offJson['before'], 'matches_before' => true])
        ->and(file_exists($state))->toBeFalse()
        ->and(file_get_contents($this->root.'/calls.log'))->toContain('--network container:ddev-interview-web', 'tso=on gso=on gro=off udp_gso=on')
        ->and(json_decode($again->getOutput(), true))->toBe(['restored' => null, 'matches_before' => null]);
});

it('AC-2 netem web-offloads off keeps the original values of an interrupted run as the ones to restore', function (): void {
    $state = $this->root.'/netem-offloads-ddev-interview-web.state';
    file_put_contents($state, json_encode(['eth0' => ['tso' => 'on', 'gso' => 'on', 'gro' => 'off', 'udp_gso' => 'on']])."\n");
    touch($this->root.'/calls.log.off');

    $off = ($this->netem)([], 'web-offloads', 'off', 'ddev-interview-web');

    expect(json_decode($off->getOutput(), true)['before']['eth0']['tso'])->toBe('on')
        ->and(file_exists($state))->toBeTrue();
});

it('AC-2 netem web-offloads off fails when the offloads cannot be turned off and keeps the state for the restore', function (): void {
    $off = ($this->netem)(['STUB_OFFLOADS_STUCK' => '1'], 'web-offloads', 'off', 'ddev-interview-web');

    expect($off->getExitCode())->toBe(1)
        ->and($off->getErrorOutput())->toContain('cannot turn tso, gso and gro off')
        ->and(file_exists($this->root.'/netem-offloads-ddev-interview-web.state'))->toBeTrue();
});

it('AC-2 netem defaults prints the default profile without calling docker and web-offloads needs an action', function (): void {
    $defaults = ($this->netem)([], 'defaults');
    $bad = ($this->netem)([], 'web-offloads', 'sideways', 'ddev-interview-web');

    expect($defaults->getExitCode())->toBe(0, $defaults->getErrorOutput())
        ->and(json_decode($defaults->getOutput(), true))->toMatchArray(['delay_ms' => 75, 'rate_up' => '750kbit', 'rate_down' => '1600kbit', 'mode' => 'bidirectional'])
        ->and(file_exists($this->root.'/calls.log'))->toBeFalse()
        ->and($bad->getExitCode())->toBe(2);
});
