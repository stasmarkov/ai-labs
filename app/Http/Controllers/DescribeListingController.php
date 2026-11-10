<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DescribeListingRequest;
use App\Services\ListingDescriptionService;
use Laravel\Ai\Responses\StreamableAgentResponse;

final class DescribeListingController extends Controller
{
    public function __invoke(int $id, DescribeListingRequest $request, ListingDescriptionService $service): StreamableAgentResponse
    {
        return $service->describe($id, $request->provider());
    }
}
