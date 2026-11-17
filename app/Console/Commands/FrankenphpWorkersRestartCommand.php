<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AdminApiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

#[Signature('frankenphp:workers:restart {--timeout=10 : Seconds the admin API call may take}')]
#[Description('Restart the PHP workers: the Caddy admin API on frankenphp-worker, octane:reload on frankenphp-octane')]
final class FrankenphpWorkersRestartCommand extends Command
{
    public function handle(AdminApiClient $adminApi): int
    {
        $mode = (string) config('ai.server.mode', 'unknown');

        if ($mode === 'frankenphp') {
            $this->line('classic mode has no workers to restart');

            return self::SUCCESS;
        }

        if ($mode !== 'frankenphp-worker' && $mode !== 'frankenphp-octane') {
            $this->error('server mode unknown');

            return self::FAILURE;
        }

        if (! $this->insideContainer()) {
            $this->error('Run this command inside the web container (ddev artisan frankenphp:workers:restart): the admin API listens on localhost only.');

            return self::FAILURE;
        }

        if (! $adminApi->answers()) {
            $this->error('admin API: no answer from '.$adminApi->baseUrl());

            return self::FAILURE;
        }

        return $mode === 'frankenphp-worker'
            ? $this->restartThroughAdminApi($adminApi)
            : $this->call('octane:reload');
    }

    /**
     * IS_DDEV_PROJECT is set in the DDEV containers only. Outside them nothing is probed: a localhost:2019 on the host
     * would be some other Caddy.
     */
    private function insideContainer(): bool
    {
        return config('ai.server.in_container') === true;
    }

    private function restartThroughAdminApi(AdminApiClient $adminApi): int
    {
        $started = hrtime(true);

        try {
            $response = $adminApi->restartWorkers((int) $this->option('timeout'));
        } catch (ConnectionException $e) {
            $this->error('admin API: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('admin API: '.$response->status());

            return self::FAILURE;
        }

        $this->line('workers restarted in '.(int) round((hrtime(true) - $started) / 1_000_000).' ms');

        return self::SUCCESS;
    }
}
