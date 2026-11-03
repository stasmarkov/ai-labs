<?php

declare(strict_types=1);

namespace App\Support;

final class ServerTiming
{
    /** @var array<string, float> */
    private array $milliseconds = [];

    public function reset(): void
    {
        $this->milliseconds = [];
    }

    /**
     * Run the callback and add its wall-clock time to the named metric.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function measure(string $metric, callable $callback): mixed
    {
        $start = hrtime(true);

        try {
            return $callback();
        } finally {
            $this->milliseconds[$metric] = ($this->milliseconds[$metric] ?? 0.0) + (hrtime(true) - $start) / 1_000_000;
        }
    }

    /**
     * The header value, for example "embed;dur=12.3, db;dur=4.5", or null when nothing was measured.
     */
    public function header(): ?string
    {
        if ($this->milliseconds === []) {
            return null;
        }

        return implode(', ', array_map(
            fn (string $metric, float $ms): string => sprintf('%s;dur=%.1f', $metric, $ms),
            array_keys($this->milliseconds),
            $this->milliseconds,
        ));
    }
}
