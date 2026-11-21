<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('C-5 MNC-1 calls frankenphp_log directly with the unchanged entry', function (): void {
    $script = tempnam(sys_get_temp_dir(), 'fplog');
    file_put_contents($script, <<<'PHP'
<?php
declare(strict_types=1);

require $argv[1].'/vendor/autoload.php';

function frankenphp_log(string $message, int $level, array $context): void
{
    $GLOBALS['calls'][] = [$message, $level, $context];
}

$record = fn (string $message): Monolog\LogRecord => new Monolog\LogRecord(
    datetime: new DateTimeImmutable('2026-11-21 10:11:12', new DateTimeZone('UTC')),
    channel: 'frankenphp',
    level: Monolog\Level::Warning,
    message: $message,
    context: ['a' => 'b'],
    extra: [],
);

$handler = new App\Logging\FrankenphpLogHandler;

$handler->handle($record('first'));
$handler->handle($record('second'));

echo json_encode([
    'calls' => $GLOBALS['calls'],
]);
PHP);

    try {
        $result = Process::run([PHP_BINARY, $script, base_path()])->throw();
    } finally {
        unlink($script);
    }

    $out = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

    expect($out['calls'])->toHaveCount(2)
        ->and([$out['calls'][0][0], $out['calls'][0][1]])->toBe(['first', 4])
        ->and($out['calls'][0][2]['level_name'])->toBe('WARNING')
        ->and($out['calls'][0][2]['channel'])->toBe('frankenphp')
        ->and($out['calls'][1][2]['context.a'])->toBe('b');
});
