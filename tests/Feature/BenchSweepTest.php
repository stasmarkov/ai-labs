<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bench-sweep-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/bin', 0777, true);

    $this->pick = function (array $sweep): string {
        $files = [];
        foreach ($sweep as $setting => [$rps, $errors]) {
            $file = $this->dir."/sweep-$setting.json";
            file_put_contents($file, json_encode(['label' => "sweep-$setting", 'rps' => $rps, 'error_percent' => $errors]));
            $files[] = $file;
        }
        $process = new Process(['bash', base_path('bench/pick-tuned.sh'), ...$files]);
        $process->mustRun();

        return trim($process->getOutput());
    };
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->dir]))->run();
});

it('AC-5 sweep picks the lower setting when RPS is within 3 percent', function (): void {
    expect(($this->pick)([8 => [800, 0], 16 => [1000, 0], 32 => [1020, 0]]))->toBe('16');
});

it('AC-5 sweep picks the higher setting when it wins by more than 3 percent', function (): void {
    expect(($this->pick)([8 => [800, 0], 16 => [1000, 0], 32 => [1100, 0]]))->toBe('32');
});

it('AC-5 sweep skips a winner above 1 percent errors', function (): void {
    expect(($this->pick)([8 => [800, 0], 16 => [900, 0.5], 32 => [1500, 2]]))->toBe('16');
});

it('AC-5 sweep picks the fewest errors when every setting exceeds 1 percent', function (): void {
    expect(($this->pick)([8 => [800, 5], 16 => [900, 3], 32 => [1500, 4]]))->toBe('16');
});

it('MNC-1 suite refuses a results folder that already holds committed results', function (): void {
    file_put_contents($this->dir.'/bin/ddev', "#!/usr/bin/env bash\necho \"ddev \$*\" >> \"$this->dir/log\"\n[[ \"\$*\" == 'server --current' ]] && echo frankenphp\nexit 0\n");
    chmod($this->dir.'/bin/ddev', 0755);
    $process = new Process(['bash', base_path('bench/suite.sh'), '--servers', 'frankenphp'], null, [
        'PATH' => $this->dir.'/bin:'.getenv('PATH'),
        'BENCH_DATE' => '2026-11-01',
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('already holds committed results')
        ->and((string) @file_get_contents($this->dir.'/log'))->not->toContain('ddev server frankenphp');
});
