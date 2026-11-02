<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;

final class FakeStreamProvider extends Provider implements TextProvider
{
    use GeneratesText;
    use HasTextGateway;
    use StreamsText;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config, protected Dispatcher $events)
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function providerCredentials(): array
    {
        return ['key' => ''];
    }

    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new FakeStreamGateway(
            tokens: max(0, (int) ($this->config['tokens'] ?? 200)),
            delayMs: max(0, (int) ($this->config['delay_ms'] ?? 0)),
            failAfter: filled($this->config['fail_after'] ?? null) ? max(0, (int) $this->config['fail_after']) : null,
        );
    }

    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'fake-slow-1';
    }

    public function cheapestTextModel(): string
    {
        return $this->defaultTextModel();
    }

    public function smartestTextModel(): string
    {
        return $this->defaultTextModel();
    }
}
