<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/photo-dataset-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/bin', 0777, true);
    $this->target = $this->dir.'/target';
    touch($this->dir.'/log');

    file_put_contents($this->dir.'/payload', "fixture parquet bytes\n");
    $this->sum = hash('sha256', "fixture parquet bytes\n");
    file_put_contents($this->dir.'/manifest', "{$this->sum} 22 data/train-00000-of-00001.parquet\n");

    file_put_contents($this->dir.'/bin/curl', <<<'SH'
#!/usr/bin/env bash
echo "curl $*" >> "$STUB_DIR/log"
out=
while [[ $# -gt 0 ]]; do
  case "$1" in
    -o) out="$2"; shift 2 ;;
    *) shift ;;
  esac
done
cp "$STUB_DIR/${STUB_PAYLOAD:-payload}" "$out"
SH);
    chmod($this->dir.'/bin/curl', 0755);

    file_put_contents($this->dir.'/bin/docker', <<<'SH'
#!/usr/bin/env bash
echo "docker $*" >> "$STUB_DIR/log"
if [[ -n "${STUB_DOCKER_FAIL-}" ]]; then
  exit 1
fi
mkdir -p "$STANFORD_CARS_DIR/images"
echo "file,class_name" > "$STANFORD_CARS_DIR/classes.csv"
SH);
    chmod($this->dir.'/bin/docker', 0755);

    $this->run = function (array $args = [], array $env = []): Process {
        $process = new Process(
            ['bash', base_path('bench/datasets/stanford-cars.sh'), ...$args],
            base_path(),
            [
                'PATH' => $this->dir.'/bin:'.getenv('PATH'),
                'STUB_DIR' => $this->dir,
                'STANFORD_CARS_DIR' => $this->target,
                'STANFORD_CARS_MANIFEST' => $this->dir.'/manifest',
                ...$env,
            ],
        );
        $process->run();

        return $process;
    };
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->dir]))->run();
});

test('AC-1 without --accept-terms the script prints the terms, exits 1 and never calls curl', function (): void {
    $process = ($this->run)();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('terms of use')
        ->toContain('research and non-commercial')
        ->toContain('https://ai.stanford.edu/~jkrause/cars/car_dataset.html');
    expect(file_get_contents($this->dir.'/log'))->toBe('');
    expect($process->getErrorOutput())->not->toContain('docker');
    expect(is_dir($this->target))->toBeFalse();
});

test('AC-1 with --accept-terms it downloads and verifies the checksum', function (): void {
    $process = ($this->run)(['--accept-terms']);

    expect($process->getExitCode())->toBe(0);
    expect($this->target.'/data/train-00000-of-00001.parquet')->toBeFile();
    expect(hash_file('sha256', $this->target.'/data/train-00000-of-00001.parquet'))->toBe($this->sum);
    expect(substr_count((string) file_get_contents($this->dir.'/log'), 'curl '))->toBe(1);
});

test('AC-1 a checksum mismatch exits 2 and keeps no file', function (): void {
    file_put_contents($this->dir.'/bad', 'tampered');
    $process = ($this->run)(['--accept-terms'], ['STUB_PAYLOAD' => 'bad']);

    expect($process->getExitCode())->toBe(2);
    expect($process->getErrorOutput())->toContain('checksum mismatch');
    expect(glob($this->target.'/data/*'))->toBe([]);
});

test('AC-1 a second run skips the verified download', function (): void {
    ($this->run)(['--accept-terms']);
    $second = ($this->run)(['--accept-terms']);

    expect($second->getExitCode())->toBe(0)
        ->and($second->getOutput())->toContain('skip data/train-00000-of-00001.parquet');
    expect(substr_count((string) file_get_contents($this->dir.'/log'), 'curl '))->toBe(1);
});

test('AC-2 a verified download runs one pinned docker conversion that writes classes.csv and images', function (): void {
    $process = ($this->run)(['--accept-terms']);

    $docker = array_values(array_filter(
        explode("\n", (string) file_get_contents($this->dir.'/log')),
        fn (string $line): bool => str_starts_with($line, 'docker '),
    ));

    expect($process->getExitCode())->toBe(0)
        ->and($docker)->toHaveCount(1)
        ->and($docker[0])->toContain('python:3.12-slim@sha256:5e2bdb1b0d542d5039cffb8181c65930093949194a65c365dfb1b0fe13a4fa5b')
        ->toContain($this->target.':/dataset')
        ->toContain('pyarrow==');
    expect($this->target.'/classes.csv')->toBeFile();
    expect($this->target.'/images')->toBeDirectory();
});

test('AC-2 a second run skips both the download and the conversion', function (): void {
    ($this->run)(['--accept-terms']);
    $second = ($this->run)(['--accept-terms']);

    $log = (string) file_get_contents($this->dir.'/log');

    expect($second->getExitCode())->toBe(0)
        ->and($second->getOutput())->toContain('skip data/train-00000-of-00001.parquet')
        ->toContain('skip conversion');
    expect(substr_count($log, 'curl '))->toBe(1);
    expect(substr_count($log, 'docker '))->toBe(1);
});

test('AC-2 a failed conversion exits 3 and leaves no classes.csv', function (): void {
    $process = ($this->run)(['--accept-terms'], ['STUB_DOCKER_FAIL' => '1']);

    expect($process->getExitCode())->toBe(3);
    expect($process->getErrorOutput())->toContain('conversion failed');
    expect($this->target.'/classes.csv')->not->toBeFile();
});

test('AC-2 a checksum mismatch never reaches docker', function (): void {
    file_put_contents($this->dir.'/bad', 'tampered');
    ($this->run)(['--accept-terms'], ['STUB_PAYLOAD' => 'bad']);

    expect(file_get_contents($this->dir.'/log'))->not->toContain('docker ');
});

test('MNC-5 the dataset folder is ignored by git', function (): void {
    $process = new Process(['git', 'check-ignore', '-q', 'storage/app/datasets/stanford-cars/data/train-00000-of-00002.parquet'], base_path());
    $process->run();

    expect($process->getExitCode())->toBe(0);

    $images = new Process(['git', 'check-ignore', '-q', 'storage/app/datasets/stanford-cars/images/train-00000-00000.jpg'], base_path());
    $images->run();

    expect($images->getExitCode())->toBe(0);
});
