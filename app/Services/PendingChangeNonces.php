<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * The confirmation nonce of a pending change. It is made when the change is shown to a browser session and kept only in
 * that session, so a client that proposed the change, and never opened the page, has no nonce to send back. A nonce is
 * checked with hash_equals and works once.
 */
final readonly class PendingChangeNonces
{
    private const KEY = 'mcp_change_nonces';

    private const LENGTH = 32;

    private const KEEP = 100;

    public function __construct(private Session $session) {}

    /**
     * The nonce for this change in this session; the same one while it is unused, so two tabs share it.
     */
    public function issue(string $id): string
    {
        $nonces = $this->nonces();

        if (isset($nonces[$id])) {
            return $nonces[$id];
        }

        $nonce = Str::random(self::LENGTH);
        $nonces[$id] = $nonce;
        $this->session->put(self::KEY, array_slice($nonces, -self::KEEP, null, true));

        return $nonce;
    }

    /**
     * Uses the nonce up when it is the one this session got for the change.
     */
    public function consume(string $id, string $supplied): bool
    {
        $nonces = $this->nonces();

        if (! isset($nonces[$id]) || ! hash_equals($nonces[$id], $supplied)) {
            return false;
        }

        unset($nonces[$id]);
        $this->session->put(self::KEY, $nonces);

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function nonces(): array
    {
        $stored = $this->session->get(self::KEY, []);

        return is_array($stored) ? array_filter($stored, is_string(...)) : [];
    }
}
