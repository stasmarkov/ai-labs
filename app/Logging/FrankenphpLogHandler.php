<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Arr;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Writes the entry FrankenphpJsonFormatter builds: through frankenphp_log() inside a FrankenPHP request, so it lands in
 * Caddy's structured log next to the access log, or as one JSON line on stderr everywhere else (CLI, queue workers).
 */
final class FrankenphpLogHandler extends AbstractProcessingHandler
{
    /** @var (callable(string, int, array<string, mixed>): void)|null */
    private $writer;

    /** @var resource|null */
    private $stderr;

    private ?FrankenphpJsonFormatter $entryFormatter = null;

    /**
     * @param  (callable(string, int, array<string, mixed>): void)|null  $writer  defaults to frankenphp_log when it exists
     * @param  resource|null  $stderr  defaults to php://stderr
     */
    public function __construct(?callable $writer = null, $stderr = null, int|string|Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->writer = $writer;
        $this->stderr = $stderr;
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new FrankenphpJsonFormatter;
    }

    protected function write(LogRecord $record): void
    {
        $formatter = $this->getFormatter();
        if (! $formatter instanceof FrankenphpJsonFormatter) {
            // A channel config that sets another formatter (Laravel gives monolog channels a line formatter unless
            // told otherwise) would hand write() a string; the entry shape is this channel's contract, so it wins.
            $formatter = $this->entryFormatter ??= new FrankenphpJsonFormatter;
            $record->formatted = $formatter->format($record);
        }

        /** @var array<string, mixed> $entry */
        $entry = $record->formatted;
        $writer = $this->writer ?? (function_exists('frankenphp_log')
            ? static function (string $message, int $level, array $context): void {
                call_user_func('frankenphp_log', $message, $level, self::slogAttributes($context));
            }
            : null);

        if ($writer !== null) {
            $writer($record->message, $this->slogLevel($record->level), [
                'level_name' => $entry['level_name'],
                'channel' => $entry['channel'],
                'request' => $entry['request'],
                'context' => $entry['context'],
            ]);

            return;
        }

        $this->stderr ??= fopen('php://stderr', 'wb') ?: null;

        if ($this->stderr !== null) {
            fwrite($this->stderr, $formatter->toJsonLine($entry));
        }
    }

    /**
     * frankenphp_log() hands the context to Go's slog, and FrankenPHP 1.13 turns a nested PHP array into an ordered map
     * that slog prints as {"Map": {...}, "Order": [...]}. Flat dotted keys (request.request_id, context.user.id) print as
     * plain JSON attributes; empty arrays carry nothing and are left out.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function slogAttributes(array $context): array
    {
        return array_filter(Arr::dot($context), static fn (mixed $value): bool => $value !== []);
    }

    /**
     * FrankenPHP takes slog levels: debug -4, info 0, warn 4, error 8. Notice has no slog level and goes to info.
     */
    private function slogLevel(Level $level): int
    {
        return match (true) {
            $level === Level::Debug => -4,
            $level->value <= Level::Notice->value => 0,
            $level === Level::Warning => 4,
            default => 8,
        };
    }
}
