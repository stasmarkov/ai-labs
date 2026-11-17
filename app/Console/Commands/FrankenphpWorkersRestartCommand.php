<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

#[Signature('frankenphp:workers:restart {--timeout=10 : Seconds the admin API call may take}')]
#[Description('Restart the PHP workers: the Caddy admin API on frankenphp-worker, octane:reload on frankenphp-octane')]
final class FrankenphpWorkersRestartCommand extends Command
{
    private const string RESTART_PATH = '/frankenphp/workers/restart';

    public function handle(): int
    {
        return match ((string) config('ai.server.mode', 'unknown')) {
            'frankenphp' => $this->classic(),
            'frankenphp-worker' => $this->insideContainer() ?? $this->restartThroughAdminApi(),
            'frankenphp-octane' => $this->insideContainer() ?? $this->reloadOctane(),
            default => $this->unknown(),
        };
    }

    private function classic(): int
    {
        $this->line('classic mode has no workers to restart');

        return self::SUCCESS;
    }

    private function unknown(): int
    {
        $this->error('server mode unknown');

        return self::FAILURE;
    }

    private function insideContainer(): ?int
    {
        if (config('ai.server.in_container') === true) {
            return null;
        }

        $this->error('Run this command inside the web container (ddev artisan frankenphp:workers:restart): the admin API listens on localhost only.');

        return self::FAILURE;
    }

    private function restartThroughAdminApi(): int
    {
        $started = hrtime(true);

        try {
            $response = Http::timeout(max(1, (int) $this->option('timeout')))
                ->post(rtrim((string) config('ai.server.admin_url'), '/').self::RESTART_PATH);
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

    private function reloadOctane(): int
    {
        $code = Artisan::call('octane:reload');
        $this->output->write(Artisan::output());

        return $code;
    }
}
