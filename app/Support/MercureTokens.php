<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final readonly class MercureTokens
{
    /**
     * @param  list<string>  $topics
     */
    public function publisher(array $topics, ?int $now = null): string
    {
        return $this->sign(
            ['mercure' => ['publish' => $topics]],
            $this->key('publisher_jwt_key'),
            (int) config('mercure.publisher_token_ttl'),
            $now,
        );
    }

    /**
     * @param  list<string>  $topics
     */
    public function subscriber(array $topics, ?int $now = null): string
    {
        return $this->sign(
            ['mercure' => ['subscribe' => $topics]],
            $this->key('subscriber_jwt_key'),
            (int) config('mercure.subscriber_token_ttl'),
            $now,
        );
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  int|null  $now  the issue time; the clock is read once, so exp - iat is always exactly $ttl.
     */
    public function sign(array $claims, string $key, int $ttl, ?int $now = null): string
    {
        $now ??= time();
        $header = $this->encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $this->encode($claims + ['iat' => $now, 'exp' => $now + $ttl]);
        $signature = $this->base64Url(hash_hmac('sha256', "{$header}.{$payload}", $key, true));

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * @return array<string, mixed>|null the claims, or null when the token is malformed, forged or expired.
     */
    public function verify(string $token, string $key): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $expected = $this->base64Url(hash_hmac('sha256', "{$header}.{$payload}", $key, true));
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $head = json_decode((string) base64_decode(strtr($header, '-_', '+/')), true);
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (! is_array($head) || ($head['alg'] ?? null) !== 'HS256' || ! is_array($claims)) {
            return null;
        }

        if (! isset($claims['exp']) || ! is_int($claims['exp']) || $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    private function key(string $name): string
    {
        $key = config("mercure.{$name}");
        if (! is_string($key) || $key === '') {
            throw new RuntimeException("Mercure {$name} is not set. Run `artisan mercure:keys`.");
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function encode(array $data): string
    {
        return $this->base64Url(json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
