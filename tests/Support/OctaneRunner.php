<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class OctaneRunner
{
    /**
     * Send the requests one after another through a single booted Octane worker.
     *
     * @param  list<string>  $uris
     * @return list<array{status: int, body: array<string, mixed>|null}>
     */
    public static function worker(array $uris): array
    {
        return self::run('worker', $uris);
    }

    /**
     * Send each request in its own PHP process with its own application boot, as a classic server does.
     *
     * @param  list<string>  $uris
     * @return list<array{status: int, body: array<string, mixed>|null}>
     */
    public static function classic(array $uris): array
    {
        $responses = [];

        foreach ($uris as $uri) {
            array_push($responses, ...self::run('classic', [$uri]));
        }

        return $responses;
    }

    /**
     * @param  list<string>  $uris
     * @return list<array{status: int, body: array<string, mixed>|null}>
     */
    private static function run(string $mode, array $uris): array
    {
        $process = new Process(
            [(new PhpExecutableFinder)->find(), __DIR__.'/octane-worker.php', $mode, json_encode($uris)],
            dirname(__DIR__, 2),
            ['DEBUG_ROUTES' => 'true', 'LARAVEL_OCTANE' => false],
        );
        $process->mustRun();

        $responses = json_decode($process->getOutput(), true);

        if (! is_array($responses)) {
            throw new RuntimeException('Worker helper printed no JSON: '.$process->getOutput().$process->getErrorOutput());
        }

        return $responses;
    }
}
