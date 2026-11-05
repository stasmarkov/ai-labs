<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Sends a 103 Early Hints response ahead of the final one. A seam so tests can record the links and so
 * a SAPI without support behaves exactly as before.
 */
interface EarlyHintSender
{
    public function isAvailable(): bool;

    /**
     * @param  list<string>  $links  Link header values, one per resource
     */
    public function send(array $links): void;
}
