<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-rename-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/bench', 0777, true);
    mkdir($this->root.'/.ddev', 0777, true);
    copy(base_path('bench/rename-project.sh'), $this->root.'/bench/rename-project.sh');
    chmod($this->root.'/bench/rename-project.sh', 0755);
    file_put_contents($this->root.'/.ddev/config.yaml', "name: interview\ntype: laravel\nwebserver_type: nginx-fpm\n");
    file_put_contents($this->root.'/.ddev/.env.web', "MERCURE_PUBLISHER_JWT_KEY=pub-secret\n");
    file_put_contents($this->root.'/.env', "APP_KEY=base64:abc\nMERCURE_SUBSCRIBER_JWT_KEY=sub-secret\n");
    file_put_contents($this->root.'/.gitignore', "/.ai/tmp/\n/bin/\n/stub.log\n/counts-*.json\n");
    exec('git -C '.escapeshellarg($this->root).' init -q && git -C '.escapeshellarg($this->root).' add -A && git -C '.escapeshellarg($this->root).' -c user.email=a@b -c user.name=t commit -q -m init');

    file_put_contents($this->root.'/bin/ddev', <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
name=$(sed -n 's/^name: //p' .ddev/config.yaml)
case "$1" in
  describe) [[ -n "$STUB_STOPPED" ]] && { echo "{\"raw\":{\"status\":\"stopped\"}}"; exit 0; }; printf "{\"raw\":{\"name\":\"%s\",\"status\":\"running\"}}\n" "$name" ;;
  export-db)
    for a in "$@"; do case "$a" in --file=*) f="${a#--file=}" ;; esac; done
    [[ -z "$STUB_EMPTY_EXPORT" ]] && echo "dump" > "$f" || : > "$f" ;;
  artisan)
    if [[ "$name" == "ai-lab" ]]; then cat "$STUB_COUNTS_AFTER"; else cat "$STUB_COUNTS_BEFORE"; fi ;;
  import-db) [[ -n "$STUB_IMPORT_FAIL" ]] && exit 1; exit 0 ;;
esac
exit 0
BASH);
    file_put_contents($this->root.'/bin/curl', <<<'BASH'
#!/usr/bin/env bash
url=""
for a in "$@"; do case "$a" in http*) url="$a" ;; esac; done
echo "curl $url" >> "$STUB_LOG"
echo "{\"url\":\"$url\",\"id\":1,\"price\":${STUB_PRICE:-100}}"
BASH);
    chmod($this->root.'/bin/ddev', 0755);
    chmod($this->root.'/bin/curl', 0755);
    file_put_contents($this->root.'/stub.log', '');
    $counts = '{"listings":50000,"listing_documents":50000,"docs_documents":40,"docs_chunks":900,"ai_runs":12,"docs_answer_cache":3,"exports":2}';
    file_put_contents($this->root.'/counts-before.json', $counts);
    file_put_contents($this->root.'/counts-after.json', $counts);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->root));
});

function renameProject(object $test, array $env = []): Process
{
    $process = new Process(['bash', $test->root.'/bench/rename-project.sh'], $test->root, array_merge([
        'PATH' => $test->root.'/bin:'.getenv('PATH'),
        'STUB_LOG' => $test->root.'/stub.log',
        'STUB_COUNTS_BEFORE' => $test->root.'/counts-before.json',
        'STUB_COUNTS_AFTER' => $test->root.'/counts-after.json',
    ], $env), null, 60);
    $process->run();

    return $process;
}

it('AC-1 and MNC-1 exports, renames, imports, compares counts and samples, and prints the delete command', function (): void {
    $process = renameProject($this);
    $out = $process->getOutput();
    $log = (string) file_get_contents($this->root.'/stub.log');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$out)
        ->and(file_get_contents($this->root.'/.ddev/config.yaml'))->toBe("name: ai-lab\ntype: laravel\nwebserver_type: nginx-fpm\n")
        ->and($this->root.'/.ai/tmp/pre-rename.sql.gz')->toBeFile()
        ->and(json_decode((string) file_get_contents($this->root.'/.ai/tmp/pre-rename/counts.json'), true)['listings'])->toBe(50000)
        ->and($out)->toContain('counts equal')
        ->and($out)->toContain('samples equal')
        ->and($out)->toContain('docker volume rm interview-postgres ddev-interview_redis interview_project_mutagen')
        ->and($log)->toContain('ddev stop --unlist interview')
        ->and($log)->not->toContain('ddev delete')
        ->and($log)->toContain('curl https://interview.ddev.site/listings?per_page=5&page=1')
        ->and($log)->toContain('curl https://ai-lab.ddev.site/listings?per_page=5&page=1');

    $order = array_values(array_filter(explode("\n", $log), fn ($l) => str_starts_with($l, 'ddev ')));
    $verbs = array_map(fn ($l) => explode(' ', $l)[1].(str_contains($l, 'server') ? ' '.explode(' ', $l)[2] : ''), $order);
    expect(array_search('export-db', $verbs))->toBeLessThan(array_search('stop', $verbs))
        ->and(array_search('stop', $verbs))->toBeLessThan(array_search('start', $verbs))
        ->and(array_search('start', $verbs))->toBeLessThan(array_search('import-db', $verbs))
        ->and(array_search('import-db', $verbs))->toBeLessThan(array_search('server default', $verbs))
        ->and(array_search('server default', $verbs))->toBeLessThan(array_search('setup', $verbs));
});

it('MNC-4 changes only the name line of .ddev/config.yaml', function (): void {
    renameProject($this);

    $diff = [];
    exec('git -C '.escapeshellarg($this->root).' diff --unified=0 -- .ddev/config.yaml | grep -E "^[+-][^+-]"', $diff);

    expect($diff)->toBe(['-name: interview', '+name: ai-lab']);
});

it('MNC-3 keeps the Mercure keys, .env and .ddev/.env.web untouched', function (): void {
    $process = renameProject($this);

    expect($process->getExitCode())->toBe(0)
        ->and(file_get_contents($this->root.'/.ddev/.env.web'))->toBe("MERCURE_PUBLISHER_JWT_KEY=pub-secret\n")
        ->and(file_get_contents($this->root.'/.env'))->toBe("APP_KEY=base64:abc\nMERCURE_SUBSCRIBER_JWT_KEY=sub-secret\n")
        ->and($process->getOutput())->toContain('keys unchanged');
});

it('AC-1 stops with rollback instructions when the counts differ after the import', function (): void {
    file_put_contents($this->root.'/counts-after.json', '{"listings":49999,"listing_documents":50000,"docs_documents":40,"docs_chunks":900,"ai_runs":12,"docs_answer_cache":3,"exports":2}');

    $process = renameProject($this);
    $log = (string) file_get_contents($this->root.'/stub.log');

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('listings')
        ->and($process->getErrorOutput())->toContain('name: interview')
        ->and($log)->not->toContain('ddev server default')
        ->and($log)->not->toContain('ddev delete');
});

it('MNC-1 stops when a sample listing differs after the rename', function (): void {
    file_put_contents($this->root.'/bin/curl', <<<'BASH'
#!/usr/bin/env bash
url=""
for a in "$@"; do case "$a" in http*) url="$a" ;; esac; done
case "$url" in *ai-lab*) price=999 ;; *) price=100 ;; esac
echo "{\"url\":\"$url\",\"price\":$price}"
BASH);

    $result = renameProject($this);

    expect($result->getExitCode())->not->toBe(0)
        ->and($result->getErrorOutput())->toContain('sample');
});

it('refuses to start when the export is empty and leaves the config alone', function (): void {
    $process = renameProject($this, ['STUB_EMPTY_EXPORT' => '1']);

    expect($process->getExitCode())->not->toBe(0)
        ->and(file_get_contents($this->root.'/.ddev/config.yaml'))->toContain('name: interview');
});

it('refuses to start when the project is not running', function (): void {
    $process = renameProject($this, ['STUB_STOPPED' => '1']);

    expect($process->getExitCode())->not->toBe(0)
        ->and(file_get_contents($this->root.'/.ddev/config.yaml'))->toContain('name: interview');
});

it('refuses to start on a dirty tree', function (): void {
    file_put_contents($this->root.'/dirty.txt', 'x');

    $process = renameProject($this);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('clean')
        ->and(file_get_contents($this->root.'/.ddev/config.yaml'))->toContain('name: interview');
});

it('only verifies when the project is already renamed and the pre-rename counts exist', function (): void {
    renameProject($this);
    file_put_contents($this->root.'/stub.log', '');

    $process = renameProject($this);
    $log = (string) file_get_contents($this->root.'/stub.log');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('counts equal')
        ->and($log)->not->toContain('ddev export-db')
        ->and($log)->not->toContain('ddev stop')
        ->and($log)->not->toContain('ddev import-db');
});
