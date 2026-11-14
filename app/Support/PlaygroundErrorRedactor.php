<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cleans the error text of a failed playground target before it is stored and shown. The bench redactor removes keys
 * and credentials; this one also removes where the call went: urls, the host of every configured provider, addresses,
 * host:port pairs and ports, so a failure never tells a reader what the server runs on.
 */
final class PlaygroundErrorRedactor
{
    /**
     * Pattern => replacement, applied in order. Model names such as `qwen3.5:9b` and `gemma4:12b` have no letters-only
     * top-level label and no all-digit tag, so they pass through.
     */
    private const PATTERNS = [
        '#\b[a-z][a-z0-9+.-]*://\S+#i' => '[url]',
        '/\[[0-9a-f:.]+\](?::\d+)?/i' => '[host]',
        '/\b(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}\b/i' => '[host]',
        '/\b(?:\d{1,3}\.){3}\d{1,3}(?::\d+)?\b/' => '[host]',
        '/\b(connect(?:ing)?\s+to|resolve\s+host:?|reach(?:ing)?)\s+[^\s,;]+/i' => '$1 [host]',
        '/\b(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(?::\d+)?\b/i' => '[host]',
        '/\b[a-z][a-z0-9_-]*:\d{2,5}\b/i' => '[host]',
        '/\blocalhost\b/i' => '[host]',
        '/\bport\s+\d+\b/i' => 'port [port]',
    ];

    public static function clean(string $text): string
    {
        $text = LlmBenchRedactor::clean($text);

        foreach (self::configuredHosts() as $host) {
            $text = (string) preg_replace('/(?<![A-Za-z0-9_.-])'.preg_quote($host, '/').'(?![A-Za-z0-9_-])/i', '[host]', $text);
        }

        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * The hosts of the configured provider urls, longest first. A single-label host that is also a provider name
     * ("ollama") is left to the connect-wording pattern, so the provider name stays readable.
     *
     * @return list<string>
     */
    private static function configuredHosts(): array
    {
        $hosts = [];

        foreach ((array) config('ai.providers', []) as $name => $entry) {
            $url = is_array($entry) ? ($entry['url'] ?? null) : null;
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

            if (is_string($host) && $host !== '' && $host !== $name) {
                $hosts[$host] = true;
            }
        }

        $hosts = array_keys($hosts);
        usort($hosts, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $hosts;
    }
}
