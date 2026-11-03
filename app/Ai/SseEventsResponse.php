<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;

/**
 * A finished set of events sent in the stream framing ("data: <json>" lines, then "data: [DONE]"), for a request that
 * never reaches the model.
 */
final readonly class SseEventsResponse implements Responsable
{
    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(private array $events) {}

    public function toResponse($request): Response
    {
        $events = $this->events;

        return response()->stream(function () use ($events): void {
            foreach ($events as $event) {
                echo 'data: '.json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
            }

            echo "data: [DONE]\n\n";

            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();
        }, headers: [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
