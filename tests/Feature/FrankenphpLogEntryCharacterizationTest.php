<?php

declare(strict_types=1);

use App\Logging\FrankenphpJsonFormatter;
use App\Logging\FrankenphpLogHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class CharacterizationPayload
{
    public function __construct(public string $name, public int $count, public string $apiKey) {}
}

/**
 * @return array{0: LogRecord, 1: int}
 */
function characterizationRecord(Level $level = Level::Warning): array
{
    $line = __LINE__ + 1;
    $exception = new RuntimeException('outer failed', 7, new LogicException('inner failed', 3));

    return [new LogRecord(
        datetime: new DateTimeImmutable('2026-11-21 10:11:12.345678', new DateTimeZone('UTC')),
        channel: 'frankenphp',
        level: $level,
        message: 'characterized',
        context: [
            'password' => 'hunter2',
            'apiKey' => 'k-1',
            'access-token' => 't-1',
            'tokens_in' => 120,
            'nested' => ['user' => ['id' => 5, 'Api_Key' => 'k-2'], 'list' => [1, 2], 'empty' => []],
            'object' => new CharacterizationPayload('car', 3, 'k-3'),
            'exception' => $exception,
        ],
        extra: [
            'request' => ['request_id' => 'abc-12345678', 'method' => 'GET', 'path' => '/cars', 'context' => 'http'],
            'host' => 'ai-lab',
        ],
    ), $line];
}

function characterizationRelative(string $text): string
{
    return str_replace(base_path().'/', '', $text);
}

const CHARACTERIZATION_FILE = 'tests/Feature/FrankenphpLogEntryCharacterizationTest.php';

it('AC-3 MNC-1 pins the formatted entry', function (): void {
    $callLine = __LINE__ + 1;
    [$record, $line] = characterizationRecord();

    $entry = (new FrankenphpJsonFormatter)->format($record);

    expect(array_keys($entry))->toBe(['time', 'level', 'level_name', 'message', 'channel', 'context', 'request'])
        ->and($entry['time'])->toBe('2026-11-21T10:11:12.345+00:00')
        ->and($entry['level'])->toBe('warning')
        ->and($entry['level_name'])->toBe('WARNING')
        ->and($entry['message'])->toBe('characterized')
        ->and($entry['channel'])->toBe('frankenphp')
        ->and($entry['request'])->toBe(['request_id' => 'abc-12345678', 'method' => 'GET', 'path' => '/cars', 'context' => 'http']);

    $context = $entry['context'];
    $exception = $context['exception'];
    unset($context['exception']);

    expect($context)->toBe([
        'password' => '[redacted]',
        'apiKey' => '[redacted]',
        'access-token' => '[redacted]',
        'tokens_in' => 120,
        'nested' => ['user' => ['id' => 5, 'Api_Key' => '[redacted]'], 'list' => [1, 2], 'empty' => []],
        'object' => ['CharacterizationPayload' => ['name' => 'car', 'count' => 3, 'apiKey' => '[redacted]']],
        'extra' => ['host' => 'ai-lab'],
    ]);

    expect(array_keys($exception))->toBe(['class', 'message', 'code', 'file', 'trace', 'previous'])
        ->and($exception['class'])->toBe(RuntimeException::class)
        ->and($exception['message'])->toBe('outer failed')
        ->and($exception['code'])->toBe(7)
        ->and(characterizationRelative($exception['file']))->toBe(CHARACTERIZATION_FILE.':'.$line)
        ->and($exception['trace'])->toBeArray()->toHaveCount(20)
        ->and(characterizationRelative($exception['trace'][0]))->toBe(CHARACTERIZATION_FILE.':'.$callLine)
        ->and(array_keys($exception['trace']))->toBe(range(0, 19))
        ->and($exception['previous'])->toBe([
            'class' => LogicException::class,
            'message' => 'inner failed',
            'code' => 3,
            'file' => base_path(CHARACTERIZATION_FILE).':'.$line,
        ]);
});

it('AC-3 MNC-1 pins the stderr JSON line', function (): void {
    $callLine = __LINE__ + 1;
    [$record, $line] = characterizationRecord(Level::Error);
    $stderr = fopen('php://memory', 'w+');

    (new FrankenphpLogHandler(stderr: $stderr))->handle($record);

    rewind($stderr);
    $raw = characterizationRelative((string) stream_get_contents($stderr));
    expect($raw)->toEndWith("}\n")->and(substr_count($raw, "\n"))->toBe(1);

    expect(preg_match('/"trace":\[(".*?")\],"previous"/', $raw, $match))->toBe(1);
    $trace = json_decode('['.$match[1].']', true, flags: JSON_THROW_ON_ERROR);
    expect($trace)->toHaveCount(20)
        ->and($trace[0])->toBe(CHARACTERIZATION_FILE.':'.$callLine)
        ->and(array_filter($trace, fn (string $frame): bool => str_starts_with($frame, '/')))->toBe([]);

    $withoutTrace = str_replace($match[0], '"trace":[],"previous"', $raw);

    expect($withoutTrace)->toBe(
        '{"time":"2026-11-21T10:11:12.345+00:00","level":"error","message":"characterized","channel":"frankenphp",'
        .'"context":{"password":"[redacted]","apiKey":"[redacted]","access-token":"[redacted]","tokens_in":120,'
        .'"nested":{"user":{"id":5,"Api_Key":"[redacted]"},"list":[1,2],"empty":[]},'
        .'"object":{"CharacterizationPayload":{"name":"car","count":3,"apiKey":"[redacted]"}},'
        .'"exception":{"class":"RuntimeException","message":"outer failed","code":7,'
        .'"file":"'.CHARACTERIZATION_FILE.':'.$line.'","trace":[],'
        .'"previous":{"class":"LogicException","message":"inner failed","code":3,'
        .'"file":"'.CHARACTERIZATION_FILE.':'.$line.'"}},'
        .'"extra":{"host":"ai-lab"}},'
        .'"request":{"request_id":"abc-12345678","method":"GET","path":"/cars","context":"http"}}'."\n"
    );
});

it('AC-3 MNC-1 pins the frankenphp_log call and the slog attributes', function (): void {
    $callLine = __LINE__ + 1;
    [$record, $line] = characterizationRecord();
    $calls = [];

    $handler = new FrankenphpLogHandler(writer: function (string $message, int $level, array $context) use (&$calls): void {
        $calls[] = [$message, $level, $context];
    });
    $handler->handle($record);

    expect($calls)->toHaveCount(1);
    [$message, $level, $context] = $calls[0];

    expect($message)->toBe('characterized')
        ->and($level)->toBe(4)
        ->and(array_keys($context))->toBe(['level_name', 'channel', 'request', 'context'])
        ->and($context['level_name'])->toBe('WARNING')
        ->and($context['channel'])->toBe('frankenphp');

    $slog = array_map(
        fn (mixed $value): mixed => is_string($value) ? characterizationRelative($value) : $value,
        FrankenphpLogHandler::slogAttributes($context),
    );

    $traceKeys = array_map(fn (int $index): string => "context.exception.trace.$index", range(0, 19));
    $trace = array_intersect_key($slog, array_flip($traceKeys));
    $rest = array_diff_key($slog, $trace);

    expect(array_keys($trace))->toBe($traceKeys)
        ->and($trace['context.exception.trace.0'])->toBe(CHARACTERIZATION_FILE.':'.$callLine);

    expect($rest)->toBe([
        'level_name' => 'WARNING',
        'channel' => 'frankenphp',
        'request.request_id' => 'abc-12345678',
        'request.method' => 'GET',
        'request.path' => '/cars',
        'request.context' => 'http',
        'context.password' => '[redacted]',
        'context.apiKey' => '[redacted]',
        'context.access-token' => '[redacted]',
        'context.tokens_in' => 120,
        'context.nested.user.id' => 5,
        'context.nested.user.Api_Key' => '[redacted]',
        'context.nested.list.0' => 1,
        'context.nested.list.1' => 2,
        'context.object.CharacterizationPayload.name' => 'car',
        'context.object.CharacterizationPayload.count' => 3,
        'context.object.CharacterizationPayload.apiKey' => '[redacted]',
        'context.exception.class' => 'RuntimeException',
        'context.exception.message' => 'outer failed',
        'context.exception.code' => 7,
        'context.exception.file' => CHARACTERIZATION_FILE.':'.$line,
        'context.exception.previous.class' => 'LogicException',
        'context.exception.previous.message' => 'inner failed',
        'context.exception.previous.code' => 3,
        'context.exception.previous.file' => CHARACTERIZATION_FILE.':'.$line,
        'context.extra.host' => 'ai-lab',
    ]);

    $position = array_search('context.exception.file', array_keys($slog), true);
    expect(array_keys($slog)[$position + 1])->toBe('context.exception.trace.0')
        ->and(array_keys($slog)[$position + 21])->toBe('context.exception.previous.class');
});

it('AC-3 MNC-1 pins the slog level mapping', function (): void {
    $expected = [
        [Level::Debug, -4], [Level::Info, 0], [Level::Notice, 0], [Level::Warning, 4],
        [Level::Error, 8], [Level::Critical, 8], [Level::Alert, 8], [Level::Emergency, 8],
    ];

    foreach ($expected as [$monologLevel, $slogLevel]) {
        $seen = null;
        $handler = new FrankenphpLogHandler(writer: function (string $message, int $level) use (&$seen): void {
            $seen = $level;
        });
        $handler->handle(characterizationRecord($monologLevel)[0]);

        expect($seen)->toBe($slogLevel, $monologLevel->getName());
    }
});

it('MNC-1 slogAttributes keeps colliding dotted keys and their order as Arr::dot did', function (): void {
    expect(FrankenphpLogHandler::slogAttributes(['a.b' => 1, 'a' => ['b' => []]]))->toBe([])
        ->and(array_keys(FrankenphpLogHandler::slogAttributes(['a' => ['b' => [], 'c' => 2], 'a.b' => 1])))->toBe(['a.b', 'a.c']);
});
