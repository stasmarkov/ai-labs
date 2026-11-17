<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class FrankenphpLogHandler extends AbstractProcessingHandler
{
    private const string REDACTED = '[redacted]';

    private const array SENSITIVE_KEYS = ['password', 'token', 'secret', 'authorization', 'api_key', 'apikey', 'cookie'];

    /** @var (callable(string, int, array<string, mixed>): void)|null */
    private $writer;

    /** @var resource|null */
    private $stderr;

    /**
     * @param  (callable(string, int, array<string, mixed>): void)|null  $writer
     * @param  resource|null  $stderr
     */
    public function __construct(?callable $writer = null, $stderr = null, int|string|Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->writer = $writer;
        $this->stderr = $stderr;
    }

    protected function write(LogRecord $record): void
    {
        $extra = $this->redact($record->extra);
        $request = $extra['request'] ?? [];
        unset($extra['request']);

        $context = $this->redact($record->context);
        if ($extra !== []) {
            $context['extra'] = $extra;
        }

        $writer = $this->writer ?? (function_exists('frankenphp_log') ? 'frankenphp_log' : null);

        if ($writer !== null) {
            $writer($record->message, $this->slogLevel($record->level), [
                'level_name' => $record->level->getName(),
                'channel' => $record->channel,
                'request' => $request,
                'context' => $context,
            ]);

            return;
        }

        $line = json_encode([
            'time' => $record->datetime->format('Y-m-d\TH:i:s.vP'),
            'level' => strtolower($record->level->getName()),
            'message' => $record->message,
            'channel' => $record->channel,
            'context' => $context,
            'request' => $request,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $this->stderr ??= fopen('php://stderr', 'w');

        if ($this->stderr !== false) {
            fwrite($this->stderr, $line."\n");
        }
    }

    private function slogLevel(Level $level): int
    {
        return match (true) {
            $level === Level::Debug => -4,
            $level->value <= Level::Notice->value => 0,
            $level === Level::Warning => 4,
            default => 8,
        };
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
