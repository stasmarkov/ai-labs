<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Ai\AdminTools\AdminResultCap;
use Carbon\CarbonImmutable;

/**
 * What a client is told after it proposed a change: the stored change id, what the developer will see and where to
 * confirm it. Nothing is written to the listing at this point.
 */
final readonly class ChangeProposal
{
    /**
     * @param  array<string, mixed>  $preview
     */
    public function __construct(
        public string $id,
        public array $preview,
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $reply = [
            'status' => 'pending',
            'change_id' => $this->id,
            'preview' => $this->preview,
            'url' => $this->url,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'note' => 'Nothing is changed yet. The confirmation needs the developer in the browser: give the developer the url to confirm or reject it.',
        ];

        if (mb_strlen(AdminResultCap::encode($reply)) > AdminResultCap::MAX_CHARACTERS) {
            $reply['preview'] = ['truncated' => true];
        }

        return $reply;
    }
}
