<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;
use Throwable;

/**
 * Turns a record into the structured entry of the frankenphp channel with Monolog's own normalizer: exceptions become
 * class, message, code, file:line and a short trace; other objects become [class => their JSON form]; dates become
 * strings. Sensitive keys are redacted after normalization, so values inside objects are covered too.
 *
 * The result is an array, not a string: the handler hands it to frankenphp_log() as it is, or encodes it as one JSON line.
 */
final class FrankenphpJsonFormatter extends NormalizerFormatter
{
    public const string REDACTED = '[redacted]';

    private const int MAX_TRACE_FRAMES = 20;

    /**
     * A normalized key (lowercase, "-" and camelCase turned into "_") is sensitive when it equals one of these or ends
     * with one. "token" is a plain suffix, so access_token and csrftoken match while tokens, tokens_in, prompt_tokens
     * and token_count do not.
     */
    private const array SENSITIVE_SUFFIXES = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'api_key', 'apikey', 'access_key', 'private_key',
        'cookie', 'bearer',
    ];

    public function __construct()
    {
        parent::__construct('Y-m-d\TH:i:s.vP');

        $this->setMaxTraceLength(self::MAX_TRACE_FRAMES);
    }

    /**
     * @return array{time: string, level: string, level_name: string, message: string, channel: string, context: array<array-key, mixed>, request: array<string, mixed>}
     */
    public function format(LogRecord $record): array
    {
        $extra = $this->redact($this->normalizeArray($record->extra));
        $request = is_array($extra['request'] ?? null) ? $extra['request'] : [];
        unset($extra['request']);

        $context = $this->redact($this->normalizeArray($record->context));
        if ($extra !== []) {
            $context['extra'] = $extra;
        }

        return [
            'time' => $this->formatDate($record->datetime),
            'level' => strtolower($record->level->getName()),
            'level_name' => $record->level->getName(),
            'message' => $record->message,
            'channel' => $record->channel,
            'context' => $context,
            'request' => $request,
        ];
    }

    /**
     * Encodes an entry as one JSON line, the stderr form. level_name is only for frankenphp_log, which has no name field.
     *
     * @param  array<string, mixed>  $entry
     */
    public function toJsonLine(array $entry): string
    {
        unset($entry['level_name']);

        return $this->toJson($entry, true)."\n";
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $values): array
    {
        $normalized = $this->normalize($values);

        return is_array($normalized) ? $normalized : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function normalizeException(Throwable $e, int $depth = 0): array
    {
        $data = parent::normalizeException($e, $depth);

        if (isset($data['previous']) && is_array($data['previous'])) {
            $data['previous'] = array_diff_key($data['previous'], ['trace' => true]);
        }

        return $data;
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
        $key = strtolower(str_replace('-', '_', (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key)));

        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
