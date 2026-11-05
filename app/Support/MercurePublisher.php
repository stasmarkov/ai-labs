<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final readonly class MercurePublisher
{
    public function __construct(private MercureTokens $tokens) {}

    /**
     * Publishes one update with its own id (the hub keeps it in its history so a late or reconnecting subscriber can replay it).
     * Encoding, signing and the POST all happen inside the try: whatever goes wrong is reported, never thrown, so the
     * caller's work is not undone by a hub that is down or a payload that cannot be encoded.
     *
     * @param  array<string, mixed>  $data
     * @return bool whether the hub accepted the update.
     */
    public function publish(string $topic, array $data, bool $private = false): bool
    {
        try {
            $body = ['id' => 'urn:uuid:'.Str::uuid(), 'topic' => $topic, 'data' => json_encode($data, JSON_THROW_ON_ERROR)];
            if ($private) {
                $body['private'] = 'on';
            }

            Http::timeout((int) config('mercure.timeout'))
                ->connectTimeout((int) config('mercure.connect_timeout'))
                ->withToken($this->tokens->publisher([$topic]))
                ->asForm()
                ->post((string) config('mercure.publish_url'), $body)
                ->throw();
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
