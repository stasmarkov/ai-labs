<?php

declare(strict_types=1);

namespace App\Debug\Leaks;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Number;

final readonly class LeakProbeRunner
{
    public function __construct(private Container $container) {}

    public function run(LeakScenario $scenario, LeakProbe $probe): LeakResult
    {
        return match ($scenario) {
            LeakScenario::DealerScope => $this->dealerScope($probe),
            LeakScenario::PriceCache => $this->priceCache($probe),
            LeakScenario::NumberLocale => $this->numberLocale($probe),
            LeakScenario::RequestAtBoot => $this->requestAtBoot($probe),
        };
    }

    private function dealerScope(LeakProbe $probe): LeakResult
    {
        $key = $probe->mode === LeakMode::Leak ? LeakLabServiceProvider::DEALER_LEAK : LeakLabServiceProvider::DEALER_FIXED;

        return new LeakResult($probe->dealer, $this->container->make($key)->resolve($probe->dealer));
    }

    private function requestAtBoot(LeakProbe $probe): LeakResult
    {
        $key = $probe->mode === LeakMode::Leak ? LeakLabServiceProvider::BOOT_REQUEST_LEAK : LeakLabServiceProvider::BOOT_REQUEST_FIXED;
        $reader = $this->container->make($key);

        return new LeakResult(
            $probe->dealer,
            $probe->mode === LeakMode::Leak ? $reader->dealerFromCapturedRequest() : $reader->dealerFromCurrentRequest(),
        );
    }

    private function priceCache(LeakProbe $probe): LeakResult
    {
        $formatter = $probe->mode === LeakMode::Leak ? new StaticPriceFormatter : new CachedPriceFormatter;
        $entry = $formatter->format($probe->amountCents, $probe->locale);

        return new LeakResult($probe->locale, $entry['locale'], [
            'formatted' => $entry['formatted'],
            'cache_entries' => $formatter instanceof StaticPriceFormatter ? StaticPriceFormatter::entries() : null,
        ]);
    }

    private function numberLocale(LeakProbe $probe): LeakResult
    {
        $configLocale = config('app.locale');
        $appLocale = App::getLocale();
        $amount = $probe->amountCents / 100;

        if ($probe->mode === LeakMode::Leak) {
            if ($probe->localeGiven) {
                Number::useLocale($probe->locale);
                config(['app.locale' => $probe->locale]);
                App::setLocale($probe->locale);
            }

            $observed = Number::defaultLocale();
            $formatted = Number::currency($amount, 'UAH');
        } else {
            $observed = $probe->locale;
            $formatted = Number::currency($amount, 'UAH', $probe->locale);
        }

        return new LeakResult($probe->locale, $observed, [
            'formatted' => $formatted,
            'config_locale' => $configLocale,
            'app_locale' => $appLocale,
        ]);
    }
}
