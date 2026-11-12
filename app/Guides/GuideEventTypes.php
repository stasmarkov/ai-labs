<?php

declare(strict_types=1);

namespace App\Guides;

final class GuideEventTypes
{
    public const KNOWN = [
        'stream_start',
        'text_delta',
        'stream_end',
        'sources',
        'citations',
        'cache_hit',
        'no_context',
        'refused',
        'tool_call',
        'tool_result',
        'tool_error',
        'error',
        'request_sent',
        'response_received',
        'validation_failed',
        'request_failed',
    ];
}
