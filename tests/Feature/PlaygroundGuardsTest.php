<?php

declare(strict_types=1);

use App\DTOs\NewPlaygroundVersion;
use App\Services\PlaygroundPromptService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

function promptFilesSnapshot(): array
{
    $snapshot = [];

    foreach (File::allFiles(resource_path('prompts')) as $file) {
        $snapshot[$file->getRelativePathname()] = md5_file($file->getPathname());
    }

    ksort($snapshot);

    return $snapshot;
}

it('MNC-1 saving prompts and versions in the playground never touches resources/prompts', function (): void {
    $before = promptFilesSnapshot();

    $service = app(PlaygroundPromptService::class);
    $prompt = $service->create('guard-check', new NewPlaygroundVersion('Sell the car.'));
    $service->addVersion($prompt->id, new NewPlaygroundVersion('Sell the car briefly.'));
    $service->delete($prompt->id);

    expect(promptFilesSnapshot())->toBe($before);
});

/**
 * The target timeout the config file computes for an env value.
 */
function configuredTargetTimeout(string $value): int
{
    $name = 'AI_PLAYGROUND_TIMEOUT';
    $saved = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];

    try {
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name.'='.$value);
        $config = require config_path('ai.php');
    } finally {
        [$env, $server, $process] = $saved;
        $env === null ? Arr::forget($_ENV, $name) : $_ENV[$name] = $env;
        $server === null ? Arr::forget($_SERVER, $name) : $_SERVER[$name] = $server;
        putenv($process === false ? $name : $name.'='.$process);
    }

    return $config['playground']['target_timeout'];
}

it('NFR-1 keeps the target timeout between 5 and 60 seconds, whatever the env says', function (string $value, int $expected): void {
    expect(configuredTargetTimeout($value))->toBe($expected);
})->with([
    'below the floor' => ['1', 5],
    'inside' => ['30', 30],
    'the ceiling' => ['60', 60],
    'above the ceiling' => ['500', 60],
    'empty' => ['', 60],
]);
