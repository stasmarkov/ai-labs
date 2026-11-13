<?php

declare(strict_types=1);

namespace App\Support;

use App\Ai\AiRunRecorder;
use RuntimeException;

/**
 * Cleans text that a bench run copies from Ollama, the app or the HTTP client before it is printed or written: url
 * credentials, bearer tokens, Authorization values, the configured provider keys and anything shaped like an API key.
 */
final class LlmBenchRedactor
{
    /**
     * Pattern => replacement, applied in order: url user info (also a user name alone, or a password with an @ in it),
     * Authorization values (header, JSON-quoted, any scheme), key headers, key query parameters, bearer tokens, and
     * the sk-, sk-ant- and AIza key shapes.
     */
    private const PATTERNS = [
        '#(://)[^/\s]*@#' => '$1',
        '/(["\']?)(?:proxy-)?authorization\1\s*[:=]\s*(["\']?)(?:(?:Bearer|Basic|Token|Digest)\s+)?[^\s"\',}]+\2/i' => '[redacted]',
        '/(["\']?)(?:x-api-key|api-key|apikey)\1\s*[:=]\s*(["\']?)[^\s"\',}&]+\2/i' => '[redacted]',
        '/([?&;\s"\'])(key|api_key|apikey|access_token|token)=[^&\s"\']+/i' => '$1$2=[redacted]',
        '/Bearer\s+[A-Za-z0-9._~+\/=-]+/i' => '[redacted]',
        '/\bsk-(?:ant-)?[A-Za-z0-9_-]{6,}/' => '[redacted]',
        '/\bAIza[0-9A-Za-z_-]{10,}/' => '[redacted]',
    ];

    public static function clean(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return AiRunRecorder::safeMessage(new RuntimeException($text));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function cleanAll(array $data): array
    {
        array_walk_recursive($data, static function (mixed &$value): void {
            if (is_string($value)) {
                $value = self::clean($value);
            }
        });

        return $data;
    }
}
