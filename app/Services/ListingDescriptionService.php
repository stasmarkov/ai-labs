<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Ai\CarDescriptionAgent;
use App\Ai\SseProtocol;
use Laravel\Ai\Responses\StreamableAgentResponse;

final readonly class ListingDescriptionService
{
    public function __construct(private ListingDetailService $listings) {}

    /**
     * Looks the listing up first, so an unknown id fails before any stream starts. The scoped recorder is resolved here,
     * per stream, because under Octane this service can outlive the request that built it.
     */
    public function describe(int $id): StreamableAgentResponse
    {
        $agent = new CarDescriptionAgent;

        return $agent
            ->stream($agent->promptFor($this->listings->find($id)))
            ->usingProtocol(new SseProtocol(app(AiRunRecorder::class)));
    }
}
