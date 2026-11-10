<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-chat-stop-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/bench', 0777, true);
    copy(base_path('bench/chat-stop.sh'), $this->root.'/bench/chat-stop.sh');
    chmod($this->root.'/bench/chat-stop.sh', 0755);
    mkdir($this->root.'/bench/lib');
    copy(base_path('bench/lib/default-server.sh'), $this->root.'/bench/lib/default-server.sh');
    copy(base_path('bench/lib/servers.sh'), $this->root.'/bench/lib/servers.sh');
    file_put_contents($this->root.'/.env', "OLLAMA_MODEL=stub-model\n");
    exec('git -C '.escapeshellarg($this->root).' init -q && git -C '.escapeshellarg($this->root).' -c user.email=a@b -c user.name=t commit -q --allow-empty -m init');

    file_put_contents($this->root.'/bin/ddev', <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$1 $2" in
  "server --current") echo "frankenphp" ;;
  "server --default") echo "${STUB_DEFAULT-frankenphp}" ;;
  "server --url") echo "https://interview.test" ;;
  "artisan tinker")
    id=$(echo "$*" | grep -o 'inv-[0-9]*' | head -1)
    # only the row of the asked invocation id exists; there is no "latest row" to fall back on
    if [[ -n "$id" && -z "$STUB_NO_ROW" && -f "$STUB_ASKED.$id" ]]; then
      echo "{\"status\":\"$STUB_STATUS\",\"tokens_out\":$STUB_TOKENS_OUT,\"stopped_after_ms\":900}"
    else
      echo null
    fi
    ;;
esac
BASH);
    file_put_contents($this->root.'/bin/curl', <<<'BASH'
#!/usr/bin/env bash
out=""; url=""; write=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    -o) out="$2"; shift 2 ;;
    -w) write="$2"; shift 2 ;;
    -d|-H) shift 2 ;;
    http*) url="$1"; shift ;;
    *) shift ;;
  esac
done
echo "curl $url" >> "$STUB_LOG"
if [[ "$url" == */api/generate ]]; then
  [[ -n "$write" ]] && echo "0.250"
  exit 0
fi
n=$(cat "$STUB_COUNTER" 2>/dev/null || echo 0); n=$((n + 1)); echo "$n" > "$STUB_COUNTER"
touch "$STUB_ASKED.inv-$n"
echo "data: {\"type\":\"sources\",\"sources\":[]}" >> "$out"
echo "data: {\"type\":\"stream_start\",\"invocation_id\":\"inv-$n\"}" >> "$out"
for i in $(seq 1 30); do
  echo 'data: {"type":"text_delta","delta":"x"}' >> "$out"
done
sleep 30
BASH);
    chmod($this->root.'/bin/ddev', 0755);
    chmod($this->root.'/bin/curl', 0755);
    file_put_contents($this->root.'/stub.log', '');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->root));
});

function chatStop(object $test, array $args, array $env = []): Process
{
    $process = new Process(['bash', $test->root.'/bench/chat-stop.sh', ...$args], $test->root, array_merge([
        'PATH' => $test->root.'/bin:'.getenv('PATH'),
        'STUB_LOG' => $test->root.'/stub.log',
        'STUB_COUNTER' => $test->root.'/counter',
        'STUB_ASKED' => $test->root.'/asked',
        'STUB_NO_ROW' => '',
        'STUB_STATUS' => 'stopped',
        'STUB_TOKENS_OUT' => '12',
        'CHAT_STOP_SETTLE' => '0',
    ], $env), null, 120);
    $process->run();

    return $process;
}

it('AC-2 and NFR-1 measures every run, writes one JSON per mode and prints a table', function (): void {
    $process = chatStop($this, ['--runs', '2'], ['STUB_TOKENS_OUT' => '12']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($process->getOutput())->toContain('mode frankenphp')
        ->and($process->getOutput())->toContain('stopped rows')
        ->and($process->getOutput())->toContain('tokens_out');

    $files = glob($this->root.'/bench/results/chat-stop/*/frankenphp.json');
    expect($files)->toHaveCount(1);

    $result = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);

    expect($result['metadata']['mode'])->toBe('frankenphp')
        ->and($result['metadata']['model'])->toBe('stub-model')
        ->and($result['requests'])->toHaveCount(2)
        ->and($result['stopped_rows'])->toBe(2)
        ->and($result['idle_probe_ms'])->toBe([250, 250, 250, 250, 250])
        ->and($result['metadata']['ollama_num_parallel'])->toBe('default')
        ->and($result['median_probe_ms_after_stop'])->toBe(250)
        ->and($result['requests'][0]['events_received'])->toBeGreaterThanOrEqual(20)
        ->and($result['metadata']['abort_after_events'])->toBe(20)
        ->and(array_column($result['requests'], 'invocation_id'))->toBe(['inv-1', 'inv-2'])
        ->and($result['probe_within_budget'])->toBeTrue()
        ->and($result['lag_within_budget'])->toBeTrue();
});

it('AC-2 attributes a row by invocation id and reports no_row when that run has none', function (): void {
    $process = chatStop($this, ['--runs', '2'], ['STUB_NO_ROW' => '1']);
    $result = json_decode((string) file_get_contents(glob($this->root.'/bench/results/chat-stop/*/frankenphp.json')[0]), true);

    expect($process->getExitCode())->toBe(0)
        ->and(array_column($result['requests'], 'status'))->toBe(['no_row', 'no_row'])
        ->and(array_column($result['requests'], 'invocation_id'))->toBe(['inv-1', 'inv-2'])
        ->and($result['stopped_rows'])->toBe(0);
});

it('AC-2 reports a mode that cannot detect the abort with its numbers', function (): void {
    $process = chatStop($this, ['--runs', '1'], ['STUB_STATUS' => 'completed', 'STUB_TOKENS_OUT' => '320']);
    $result = json_decode((string) file_get_contents(glob($this->root.'/bench/results/chat-stop/*/frankenphp.json')[0]), true);

    expect($process->getExitCode())->toBe(0)
        ->and($result['stopped_rows'])->toBe(0)
        ->and($result['requests'][0]['status'])->toBe('completed')
        ->and($result['median_tokens_after_stop'])->toBeGreaterThan(250)
        ->and($result['lag_within_budget'])->toBeFalse();
});

it('AC-3 switches modes with ddev server and ends on the default server', function (): void {
    chatStop($this, ['--modes', 'frankenphp-worker,frankenphp-octane', '--runs', '1'], ['STUB_DEFAULT' => 'frankenphp']);
    $log = (string) file_get_contents($this->root.'/stub.log');

    expect(glob($this->root.'/bench/results/chat-stop/*/*.json'))->toHaveCount(2)
        ->and(strpos($log, 'ddev server frankenphp-worker'))->toBeLessThan(strpos($log, 'ddev server frankenphp-octane'))
        ->and(trim((string) array_slice(explode("\n", trim($log)), -1)[0]))->toBe('ddev server frankenphp');
});

it('AC-2 refuses a removed mode before measuring anything', function (): void {
    $process = chatStop($this, ['--modes', 'frankenphp,nginx-fpm', '--runs', '1']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('nginx-fpm was removed in SL-50')
        ->and(glob($this->root.'/bench/results/chat-stop/*/*.json'))->toBe([])
        ->and((string) @file_get_contents($this->root.'/stub.log'))->not->toContain('ddev server frankenphp');
});

it('AC-2 refuses to overwrite a mode result without --force', function (): void {
    chatStop($this, ['--runs', '1']);
    $second = chatStop($this, ['--runs', '1']);

    expect($second->getExitCode())->toBe(2)
        ->and($second->getErrorOutput())->toContain('pass --force');
});

it('AC-2 and NFR-1 records the Ollama parallel setting from the environment', function (): void {
    chatStop($this, ['--runs', '1'], ['OLLAMA_NUM_PARALLEL' => '2']);
    $result = json_decode((string) file_get_contents(glob($this->root.'/bench/results/chat-stop/*/frankenphp.json')[0]), true);

    expect($result['metadata']['ollama_num_parallel'])->toBe('2')
        ->and($result['idle_probe_ms'])->toHaveCount(5);
});
