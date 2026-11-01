<?php

declare(strict_types=1);

namespace App\Debug\Leaks;

use Illuminate\Support\ServiceProvider;

final class LeakLabServiceProvider extends ServiceProvider
{
    public const string DEALER_LEAK = 'lab.dealer.leak';

    public const string DEALER_FIXED = 'lab.dealer.fixed';

    public const string BOOT_REQUEST_LEAK = 'lab.boot-request.leak';

    public const string BOOT_REQUEST_FIXED = 'lab.boot-request.fixed';

    public function register(): void
    {
        if (config('app.debug_routes') !== true) {
            return;
        }

        $this->app->singleton(self::DEALER_LEAK, CurrentDealer::class);
        $this->app->scoped(self::DEALER_FIXED, CurrentDealer::class);
        $this->app->singleton(self::BOOT_REQUEST_LEAK, BootCapturedRequest::class);
        $this->app->scoped(self::BOOT_REQUEST_FIXED, BootCapturedRequest::class);
    }

    /**
     * The leak-mode services are resolved here on purpose, so they belong to the booted application
     * that an Octane worker keeps for its whole life.
     */
    public function boot(): void
    {
        if (config('app.debug_routes') !== true) {
            return;
        }

        $this->app->make(self::DEALER_LEAK);
        $this->app->make(self::BOOT_REQUEST_LEAK);
    }
}
