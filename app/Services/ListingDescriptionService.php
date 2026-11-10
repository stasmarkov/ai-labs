<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiRunRecorder;
use App\Ai\CarDescriptionAgent;
use App\Ai\SseProtocol;
use App\Exceptions\ProviderNotConfiguredException;
use Laravel\Ai\Responses\StreamableAgentResponse;

final readonly class ListingDescriptionService
{
    public function __construct(private ListingDetailService $listings) {}

    /**
     * Looks the listing up first, so an unknown id fails before any stream starts. The scoped recorder is resolved here,
     * per stream, because under Octane this service can outlive the request that built it. The provider is resolved
     * before the stream opens, so a key-less cloud provider fails as a JSON error and not inside the stream.
     *
     * @throws ProviderNotConfiguredException
     */
    public function describe(int $id, ?string $provider = null): StreamableAgentResponse
    {
        $agent = (new CarDescriptionAgent)->usingProvider($provider);

        $prompt = $agent->promptFor($this->listings->find($id));

        $agent->provider();

        return $agent
            ->stream($prompt)
            ->usingProtocol(new SseProtocol(app(AiRunRecorder::class)));
    }
}
