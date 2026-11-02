<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Ai\CarDescriptionAgent;
use App\Ai\SseProtocol;
use Laravel\Ai\Responses\StreamableAgentResponse;

final readonly class ListingDescriptionService
{
    public function __construct(private ListingDetailService $listings, private AiRunRecorder $recorder) {}

    /**
     * Looks the listing up first, so an unknown id fails before any stream starts.
     */
    public function describe(int $id): StreamableAgentResponse
    {
        $agent = new CarDescriptionAgent;

        return $agent
            ->stream($agent->promptFor($this->listings->find($id)))
            ->usingProtocol(new SseProtocol($this->recorder));
    }
}
