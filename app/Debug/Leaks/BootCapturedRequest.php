<?php

declare(strict_types=1);

namespace App\Debug\Leaks;

use Illuminate\Http\Request;

/**
 * Receives the request in its constructor, so it keeps whichever request existed when it was built.
 */
final readonly class BootCapturedRequest
{
    public function __construct(private Request $request) {}

    public function dealerFromCapturedRequest(): ?int
    {
        $dealer = $this->request->query('dealer');

        return $dealer === null ? null : (int) $dealer;
    }

    public function dealerFromCurrentRequest(): ?int
    {
        $dealer = request()->query('dealer');

        return $dealer === null ? null : (int) $dealer;
    }
}
