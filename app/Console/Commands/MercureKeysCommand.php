<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('mercure:keys
    {--force : Replace keys that already exist}
    {--env-file= : Laravel env file (default .env)}
    {--ddev-file= : Env file DDEV loads into the web container (default .ddev/.env.web)}')]
#[Description('Generate the Mercure publisher and subscriber JWT keys into .env and .ddev/.env.web')]
final class MercureKeysCommand extends Command
{
    private const NAMES = ['MERCURE_PUBLISHER_JWT_KEY', 'MERCURE_SUBSCRIBER_JWT_KEY'];

    public function handle(): int
    {
        $files = [
            (string) ($this->option('env-file') ?: base_path('.env')),
            (string) ($this->option('ddev-file') ?: base_path('.ddev/.env.web')),
        ];
        $force = (bool) $this->option('force');

        foreach ($files as $file) {
            if (! $force && $this->hasKeys($file)) {
                $this->error("{$file} already holds Mercure keys. Use --force to replace them.");

                return self::FAILURE;
            }
        }

        $keys = [];
        foreach (self::NAMES as $name) {
            $keys[$name] = bin2hex(random_bytes(32));
        }

        foreach ($files as $file) {
            $this->write($file, $keys);
        }

        $this->info('Mercure keys written to .env and .ddev/.env.web. Run `ddev restart` so Caddy reads them.');

        return self::SUCCESS;
    }

    private function hasKeys(string $file): bool
    {
        if (! is_file($file)) {
            return false;
        }

        foreach (self::NAMES as $name) {
            if (preg_match('/^'.$name.'=.+$/m', (string) file_get_contents($file)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function write(string $file, array $keys): void
    {
        $contents = is_file($file) ? (string) file_get_contents($file) : '';

        foreach ($keys as $name => $value) {
            $line = "{$name}={$value}";
            $count = 0;
            $contents = preg_replace('/^'.$name.'=.*$/m', $line, $contents, -1, $count) ?? $contents;
            if ($count === 0) {
                $contents = $contents === '' || str_ends_with($contents, "\n") ? $contents : $contents."\n";
                $contents .= $line."\n";
            }
        }

        $this->writeOwnerOnly($file, $contents);
    }

    /**
     * The file is created 0600 (tempnam) in the target directory, filled, then renamed over the target,
     * so the keys are never readable by other users, not even for a moment.
     */
    private function writeOwnerOnly(string $file, string $contents): void
    {
        $temp = tempnam(dirname($file), '.mercure-keys-');
        if ($temp === false) {
            throw new RuntimeException("Cannot create a temporary file next to {$file}.");
        }

        chmod($temp, 0600);
        file_put_contents($temp, $contents);

        if (! rename($temp, $file)) {
            unlink($temp);

            throw new RuntimeException("Cannot write {$file}.");
        }
    }
}
